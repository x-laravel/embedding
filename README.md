# Embedding

[![Tests](https://github.com/x-laravel/embedding/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/embedding/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

A Laravel package that automatically generates and stores vector embeddings for Eloquent models using `laravel/ai`.

## How It Works

- Add the `Embeddable` trait to any model — embeddings are generated automatically on save
- Define one **or more named slots** per model, each with its own text and trigger fields
- When a field changes, only the slots that depend on that field are re-embedded
- Embedding generation is handled by a queued job per slot — no blocking
- Models can publish scalar attributes as a **payload** (`#[EmbedPayload]`) and similarity searches can filter on them at the database level via `filter:` — see [Payload filtering](#payload-filtering)
- Similarity search is driver-based: PHP by default (works with any database), or native DB-level vector search via dedicated drivers for MySQL HeatWave, MariaDB 11.7+, PostgreSQL (pgvector), Oracle 26ai, SQL Server 2025, and Qdrant — see [Similarity Drivers](#similarity-drivers)
- Optional second-stage [reranking](#reranking) reorders candidate results using `laravel/ai`'s rerank gateway (Cohere, Voyage, Jina)

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- `laravel/ai ^0.6`

## Installation

```bash
composer require x-laravel/embedding
```

Publish and run the migrations (migrations are not loaded automatically):

```bash
php artisan vendor:publish --tag=embedding-migrations
php artisan migrate
```

> Using a DB driver package (MySQL, pgsql, Oracle, …)? Publish the **driver** migrations instead — see [Database](#database).

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=embedding-config
```

## Setup

### 1. Single-slot model

For most use cases, ignore the `$slot` argument and list trigger fields in `$embeddable`:

```php
use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->title . ' ' . $this->body;
    }
}
```

### 2. Multi-slot model

Build the requested slot's text from the `$slot` argument and use a nested `$embeddable` map to define which fields trigger each slot. Only the requested slot's text is ever built — the other slots' texts are never computed:

```php
class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    protected array $embeddable = [
        'title' => ['title'],
        'body'  => ['body'],
        'full'  => ['title', 'body'],
    ];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return match ($slot) {
            'title' => $this->title,
            'body'  => $this->body,
            'full'  => $this->title . ' ' . $this->body,
        };
    }
}
```

When `title` changes, only the `title` and `full` slots are re-embedded — `body` is left untouched.

### 3. Defining trigger fields with `#[EmbedOn]`

As an alternative to `$embeddable`, use the `#[EmbedOn]` attribute. The attribute is repeatable for multi-slot models:

```php
use XLaravel\Embedding\Attributes\EmbedOn;

// Single slot
#[EmbedOn(['title', 'body'])]
class Post extends Model implements HasEmbeddings { ... }

// Multiple slots
#[EmbedOn('title', slot: 'title')]
#[EmbedOn('body', slot: 'body')]
#[EmbedOn(['title', 'body'], slot: 'full')]
class Post extends Model implements HasEmbeddings { ... }
```

`$embeddable` and `#[EmbedOn]` merge — you can use both.

### 4. Payload — filterable metadata (optional)

Declare which attributes should be stored alongside the vectors for database-level filtering with `#[EmbedPayload]`:

```php
use XLaravel\Embedding\Attributes\EmbedPayload;

#[EmbedOn('name')]
#[EmbedPayload(['province_id', 'category_id', 'active'])]
class Venue extends Model implements HasEmbeddings
{
    use Embeddable;

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->name;
    }
}
```

The payload is written to a separate `embeddables` table — **one row per entity**, independent of slots — by a lightweight queued job (`SyncModelPayload`) whenever a payload field changes. Values must be scalars (int / string / bool / null) or arrays of scalars.

Variants:

```php
// Wildcard — every attribute except the primary key, $hidden, and the except list.
// Lenient mode: dates and backed enums are serialized, incompatible values are skipped.
#[EmbedPayload('*')]
#[EmbedPayload('*', except: ['internal_notes'])]

// Computed values — define the method; it merges over the attribute fields and wins on conflicts.
public function toEmbeddingPayload(): array
{
    return ['region' => $this->city->region_id];
}
```

Values computed in `toEmbeddingPayload()` from non-column sources are invisible to dirty-tracking — call `$model->syncEmbeddingPayload()` yourself when they change:

```php
$venue->syncEmbeddingPayload();   // synchronous upsert; no-op if the model defines no payload
```

Models that define no payload never get an `embeddables` row.

## Usage

### Generating embeddings

```php
$post->embed();               // dispatch async job (default slot)
$post->embed('title');        // dispatch for a specific slot
$post->embedSync();           // synchronous
$post->embedSync('body');
$post->hasEmbedding(): bool
$post->hasEmbedding('full'): bool

$post->embedding()            // MorphOne scoped to 'default' slot
$post->embedding('title')     // MorphOne scoped to 'title' slot
$post->embeddings()           // MorphMany — all slots
```

### Suppressing embedding generation

```php
Post::withoutEmbedding(fn() => Post::create([...]));  // suppress for closure
Post::disableEmbedding();                              // suppress globally
Post::enableEmbedding();
```

### Similarity search

All similarity methods accept an optional `slot` parameter (defaults to `'default'`):

```php
// Find models most similar to a query vector
Post::similarTo($vector, limit: 10);
Post::similarTo($vector, limit: 10, slot: 'title');

// Filter by minimum similarity score and Eloquent constraints
Post::similarTo($vector, threshold: 0.8, where: fn($q) => $q->where('status', 'published'));

// Auto-embed a text query, then search
Post::similarToText('web framework', limit: 10);
Post::similarToText('web framework', slot: 'body');

// Rank an existing collection by similarity to a text or vector
Post::rankByRelevance($posts, 'web framework');
Post::rankByRelevance($posts, $vector, slot: 'full');

// Compare two models or a model with a vector
$post->similarityTo($otherPost): float
$post->similarityTo($otherPost, slot: 'title'): float
$post->similarityTo($vector): float

// Find the most similar records to this model, excluding itself
$post->mostSimilar(limit: 5);
$post->mostSimilar(limit: 5, slot: 'full');
```

All similarity methods set a `similarity_score` attribute (float) on each returned model.

`threshold` defaults to `0.0` — pass a value between `0.0` and `1.0` to filter low-scoring results.

### Payload filtering

`similarTo()`, `similarToText()` and `mostSimilar()` accept a `filter:` argument that constrains results by the values stored via [`#[EmbedPayload]`](#4-payload--filterable-metadata-optional). The filter runs inside the similarity SQL (a `whereExists` against the `embeddables` table) — never as a post-query PHP pass:

```php
Venue::similarTo($vector, limit: 300, slot: 'name',
    filter: ['province_id' => 34]);                    // equality
    filter: ['category_id' => [3, 7]]);                // IN (array value)
    filter: ['province_id' => 34, 'active' => true]);  // AND (multiple keys)
```

Semantics are intentionally minimal — **equality, IN, and AND**. There is no range / OR / nested syntax. Comparisons are type-strict: `34` does not match `"34"`. Records without a payload row never match a filtered search.

**`filter` vs `where`:** use `filter` for high-cardinality, indexable constraints that belong in the payload (tenant, region, category); use the `where` closure for one-off or complex Eloquent constraints on the model's own table. When both are given, both apply.

### Reranking

Cosine similarity is good at narrowing a large corpus down to candidates, but mixing in a rerank model on top — Cohere, Voyage, Jina — usually produces noticeably better top-K ordering for RAG pipelines. The package exposes this as a Collection macro that delegates to `laravel/ai`'s reranking gateway:

```php
$results = Post::similarTo($vector, limit: 50)
    ->rerankWithScores('UUID primary key performance', take: 5);
```

Each returned model carries a `rerank_score` attribute alongside the existing `similarity_score`, sorted by rerank score descending. JSON responses include both attributes automatically — formatting and visibility are left to your application layer.

Full signature:

```php
$collection->rerankWithScores(
    string $query,
    int $take = 0,                // 0 = keep all; otherwise top-N (passed as the provider's `top_n`)
    float $threshold = 0.0,       // 0.0 = no filter; results below this are dropped locally
    ?string $field = null,        // model column to use as the document text; defaults to toEmbeddingText()
    string $slot = 'default',     // for multi-slot models, which slot's text to rerank
);
```

Empty collections and single-item collections short-circuit — no API call is made.

The active provider follows `laravel/ai`'s `ai.default_for_reranking` config; the package does not add a second layer of provider/model configuration. If you need direct access (e.g. to rerank a manually fetched collection) resolve the service from the container:

```php
use XLaravel\Embedding\Reranker;

$reranked = app(Reranker::class)->rerank($candidates, query: 'UUID performance', take: 5);
```

## Similarity Drivers

The `php` driver is built-in and works with any database — it loads vectors into PHP and computes cosine similarity in memory. For DB-level vector search, install the appropriate driver:

| Driver | Database | Operation |
|--------|----------|-----------|
| _(built-in)_ | Any (SQLite, MySQL 8, …) | `php` — PHP-side cosine similarity |
| [embedding-mysql-driver](https://github.com/x-laravel/embedding-mysql-driver) | MySQL HeatWave | `VEC_DISTANCE_COSINE` |
| [embedding-mariadb-driver](https://github.com/x-laravel/embedding-mariadb-driver) | MariaDB 11.7+ | `VEC_Distance_Cosine` |
| [embedding-pgsql-driver](https://github.com/x-laravel/embedding-pgsql-driver) | PostgreSQL + pgvector | `<=>` operator |
| [embedding-oracle-driver](https://github.com/x-laravel/embedding-oracle-driver) | Oracle 26ai | `VECTOR_DISTANCE` |
| [embedding-sqlsrv-driver](https://github.com/x-laravel/embedding-sqlsrv-driver) | SQL Server 2025 / Azure SQL | `VECTOR_DISTANCE` |
| [embedding-qdrant-driver](https://github.com/x-laravel/embedding-qdrant-driver) | Qdrant | `$vectorSearch` REST API |

When a driver is installed, the `auto` selector detects the DB connection and switches automatically. Override via config or register a custom driver:

```php
// config/embedding.php
'similarity' => ['driver' => 'pgsql'],

// Custom driver
app(SimilarityManager::class)->extend('custom', fn() => new MyDriver());
```

## Plugins

Optional add-on packages that extend the core with non-storage concerns. Install only the ones you need.

| Plugin | Purpose |
|--------|---------|
| [embedding-pulse-plugin](https://github.com/x-laravel/embedding-pulse-plugin) | Laravel Pulse cards and recorders — per-slot throughput, generation latency (p50/p95/max + slow-call threshold), failed-job tracking, and a live "embeddings by slot" counter for the Pulse dashboard. |

### embedding-pulse-plugin

```bash
composer require x-laravel/embedding-pulse-plugin
```

Auto-discovered. Adds four Livewire cards (`embedding.throughput`, `embedding.latency`, `embedding.failures`, `embedding.slots`) that you drop into your Pulse dashboard:

```blade
<x-pulse>
    <livewire:embedding.throughput cols="6" />
    <livewire:embedding.latency cols="6" />
    <livewire:embedding.failures cols="full" />
    <livewire:embedding.slots cols="4" />
</x-pulse>
```

Recorders listen to `ModelEmbedded` / `ModelEmbedding` / `JobFailed` and write into Pulse's own storage — no extra tables. See the [plugin README](https://github.com/x-laravel/embedding-pulse-plugin) for configuration details.

## Model Events

Callbacks receive `$model` and `$slot` as arguments:

```php
// Static listeners
Post::onEmbedding(fn($post, $slot) => ...);  // before generation
Post::onEmbedded(fn($post, $slot) => ...);   // after record saved

// Observer class
class PostObserver
{
    public function embedding(Post $post, string $slot): void { ... }
    public function embedded(Post $post, string $slot): void { ... }
}
```

Laravel events `ModelEmbedding` and `ModelEmbedded` are also fired and each carry `$model`, `$slot`, and (for `ModelEmbedded`) `$embedding`.

## Soft Delete

By default, deleting a model deletes **all** its slot embeddings. Set `embedding.soft_delete` to `true` to preserve them on soft delete.

Per-model override:

```php
class Post extends Model implements HasEmbeddings
{
    use Embeddable, SoftDeletes;

    protected bool $keepEmbeddingOnSoftDelete = true;
}
```

| Event | `false` (default) | `true` |
|-------|-------------------|--------|
| soft delete | all slot embeddings deleted | embeddings kept |
| restore | all slots regenerated | unchanged |
| force delete | all slot embeddings deleted | all slot embeddings deleted |

## Artisan Commands

The CLI mirrors the package's two independent write paths: `embedding:vector:*` commands only ever touch the `embeddings` table, `embedding:payload:*` commands only ever touch the `embeddables` table. Two umbrella commands (`embedding:clear`, `embedding:clean`) operate on both tables at once for full-reset / full-cleanup workflows.

### `embedding:vector:generate`

```bash
php artisan embedding:vector:generate                                # auto-discover models in app/Models
php artisan embedding:vector:generate "App\Models\Post"              # missing embeddings, all slots
php artisan embedding:vector:generate "App\Models\Post" --slot=title # specific slot only
php artisan embedding:vector:generate "App\Models\Post" --limit=100  # at most 100 records per slot
php artisan embedding:vector:generate "App\Models\Post" --chunk=500  # fetch records 500 at a time
php artisan embedding:vector:generate "App\Models\Post" --sync       # generate inline instead of queueing
php artisan embedding:vector:generate "App\Models\Post" --force      # regenerate all records, all slots
php artisan embedding:vector:generate --dry-run                      # report counts, dispatch nothing
php artisan embedding:vector:generate -v                             # verbose: show stack traces / discovery skips
```

When the model argument is omitted, the command scans `app/Models` (or `app/`) for classes implementing `HasEmbeddings`, asks for confirmation if more than one is found, and processes them sequentially. Failures are isolated per model and a summary is printed at the end.

### `embedding:payload:sync`

Backfill missing `embeddables` rows for models with payload definitions — no AI calls, no vectors.

```bash
php artisan embedding:payload:sync                                   # auto-discover models in app/Models
php artisan embedding:payload:sync "App\Models\Venue"                # missing payload rows only
php artisan embedding:payload:sync "App\Models\Venue" --limit=100    # at most 100 records
php artisan embedding:payload:sync "App\Models\Venue" --chunk=500    # fetch records 500 at a time
php artisan embedding:payload:sync "App\Models\Venue" --sync         # upsert inline instead of queueing
php artisan embedding:payload:sync "App\Models\Venue" --force        # re-sync all rows, including existing ones
php artisan embedding:payload:sync --dry-run                         # report counts, dispatch nothing
```

Idempotent — records that already have a payload row are skipped unless `--force` is given. Use it as the backfill step after upgrading to v2 or after adding `#[EmbedPayload]` to a model with existing records. Payload-less models warn and count 0.

### `embedding:vector:clear` / `embedding:payload:clear` / `embedding:clear`

Bulk-delete stored records. All three require either a model class or `--all`.

```bash
# Vector table only (slot-aware)
php artisan embedding:vector:clear "App\Models\Post"                 # all embeddings for Post
php artisan embedding:vector:clear "App\Models\Post" --slot=title    # only the title slot for Post
php artisan embedding:vector:clear --slot=title --all                # delete the title slot across every model
php artisan embedding:vector:clear --all                             # truncate the entire embeddings table

# Payload table only
php artisan embedding:payload:clear "App\Models\Venue"               # all payload rows for Venue
php artisan embedding:payload:clear --all                            # truncate the entire embeddables table

# Both tables at once (umbrella — no --slot, the payload is entity-level)
php artisan embedding:clear "App\Models\Venue"                       # embeddings + payload rows for Venue
php artisan embedding:clear --all                                    # truncate both tables
```

`--chunk=500`, `--force` and `--dry-run` work on all three. `embedding:vector:clear` never touches payload rows and `embedding:payload:clear` never touches vectors — use the umbrella `embedding:clear` for a full reset.

### `embedding:vector:clean` / `embedding:payload:clean` / `embedding:clean`

Tidy up stale rows.

```bash
# Vector table only
php artisan embedding:vector:clean                                   # delete orphans + invalid-slot records
php artisan embedding:vector:clean --orphans-only                    # only remove orphans
php artisan embedding:vector:clean --invalid-slots-only              # only remove records with unknown slots

# Payload table only
php artisan embedding:payload:clean                                  # delete stale embeddables rows

# Both tables at once (umbrella)
php artisan embedding:clean                                          # orphans + invalid slots + stale payload rows
```

`--chunk=500`, `--force` and `--dry-run` work on all three. **Orphans** are records whose model class is missing or whose model row no longer exists; **invalid-slot** records point at a slot no longer defined in the model's `embeddingSlotMap()`; **stale payload** rows additionally cover models that no longer define a payload.

### `embedding:vector:status` / `embedding:payload:status`

Read-only health reports. `embedding:vector:status` covers configuration, per-slot coverage, orphan / invalid-slot counts, and storage size; `embedding:payload:status` covers payload configuration, per-model payload coverage, stale rows, embedded entities missing a payload row (with an `embedding:payload:sync` backfill hint), and storage size. Useful after deployments or as a periodic monitoring check.

```bash
php artisan embedding:vector:status                                  # report on every discovered HasEmbeddings model
php artisan embedding:vector:status "App\Models\Post"                # restrict to a single model
php artisan embedding:vector:status "App\Models\Post" --slot=title   # restrict to a single slot
php artisan embedding:vector:status --json                           # machine-readable output (CI / monitoring)

php artisan embedding:payload:status                                 # payload-side report
php artisan embedding:payload:status "App\Models\Venue"              # restrict to a single model
php artisan embedding:payload:status --json                          # machine-readable output
```

### `embedding:storage`

A cheap, read-only storage snapshot of both tables — exactly two metrics reads, none of the coverage / health scans the status commands run. Ideal for cron-driven monitoring on large tables.

```bash
php artisan embedding:storage                                        # per-table Rows / Data / Index / Total + combined total
php artisan embedding:storage --json                                 # {"vector": {...}, "payload": {...}}
```

The combined total is only printed when both drivers supply byte figures — a partial sum would silently understate it.

Sample `embedding:vector:status` output:

```
Configuration:
+--------------------+--------+------------------------+----------------+
| Setting            | Value  | Detail                 | Note           |
+--------------------+--------+------------------------+----------------+
| Similarity Driver  | php    |                        | auto from mysql|
| Vector Dimensions  | 1536   |                        |                |
| DB Connection      | mysql  | table: embeddings      |                |
| Queue Connection   | redis  | queue: embedding       |                |
| Embedding Provider | openai | text-embedding-3-small |                |
| Rerank Provider    | cohere | rerank-v3.5            |                |
+--------------------+--------+------------------------+----------------+

Model Coverage:
+-------------------+---------+---------+----------+----------+
| Model             | Slot    | Records | Embedded | Coverage |
+-------------------+---------+---------+----------+----------+
| App\Models\Post   | default | 1,250   | 1,200    | 96.0%    |
| App\Models\Post   | summary | 1,250   | 1,250    | 100.0%   |
| App\Models\Article| default | 500     | 500      | 100.0%   |
+-------------------+---------+---------+----------+----------+

Health:
  Orphan records (missing models):    12  → Run embedding:vector:clean to fix.
  Invalid slots (stale definitions):  0
  Total stored vectors:               2,950

Storage:
  Rows:       2,950
  Data:       104.93 MB
  Index:      19.09 MB
  Total size: 124.07 MB
```

Storage metrics are read through the `VectorStoreMetrics` contract (`embedding:vector:status`) and its payload counterpart `PayloadStoreMetrics` (`embedding:payload:status`). The core package ships default implementations (`JsonVectorStoreMetrics` / `DatabasePayloadStoreMetrics`) that return the row count via Eloquent and `null` for every byte field — DB-specific driver packages override the bindings in their service provider to provide native byte figures.

You can read the same metrics from your own code:

```php
use XLaravel\Embedding\Contracts\VectorStoreMetrics;

$snapshot = app(VectorStoreMetrics::class)->snapshot();
// Without a driver:
// ['rows' => 2950, 'bytes' => null, 'data_bytes' => null, 'index_bytes' => null]
//
// With (for example) the MySQL driver bound:
// ['rows' => 2950, 'bytes' => 130023424, 'data_bytes' => 110003200, 'index_bytes' => 20020224]
```

`rows` is always an `int`. The byte fields are `int|null` — `null` means the driver cannot or will not supply that metric (insufficient privileges, unsupported backend, etc.) and is rendered as `n/a` by `embedding:vector:status`. `rows` may be approximate when a driver reports it via fast metadata tables (e.g. MySQL `information_schema.tables.table_rows`); for an exact count, use `XLaravel\Embedding\Models\Embedding::count()` instead.

## Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `EMBEDDING_DIMENSIONS` | `1536` | Vector size — must match your AI model's output |
| `EMBEDDINGS_DATABASE_CONNECTION` | `DB_CONNECTION` | Dedicated DB connection for embeddings |
| `EMBEDDINGS_DB_TABLE` | `embeddings` | Vector table name |
| `EMBEDDABLES_DB_TABLE` | `embeddables` | Payload table name |
| `QUEUE_CONNECTION` | `sync` | Queue connection for both job types |
| `EMBEDDING_GENERATE_QUEUE` | `embedding.generate` | Queue name for vector generation jobs |
| `EMBEDDING_SYNC_PAYLOAD_QUEUE` | `embedding.sync-payload` | Queue name for payload sync jobs |
| `EMBEDDING_MAX_LENGTH` | `null` | Truncate input text before the API call (`null` = no limit) |
| `EMBEDDING_SIMILARITY_DRIVER` | `auto` | Force a specific similarity driver (`php`, or an installed DB driver) |

Payload jobs run on their own queue so the fast DB upsert never waits behind slow AI-bound vector jobs. Workers should listen to both, payload first:

```bash
php artisan queue:work --queue=embedding.sync-payload,embedding.generate
```

> **SQS:** queue names cannot contain dots — override both queue envs with hyphenated names.

## Database

```
embeddings                                     embeddables
├── id                                         ├── id
├── embeddable_type   (polymorphic)            ├── embeddable_type   (polymorphic)
├── embeddable_id                              ├── embeddable_id
├── slot              (varchar 64)             ├── payload           (json)
├── vector            (json)                   ├── created_at
├── created_at                                 └── updated_at
└── updated_at                                     unique: (embeddable_type, embeddable_id)
    unique: (embeddable_type, embeddable_id, slot)
```

The two tables are matched by the `(embeddable_type, embeddable_id)` morph pair — no foreign key. `embeddings` holds one row per model per slot; `embeddables` holds **one payload row per entity**, written only for models that define a payload. Vector writes and payload writes are fully independent paths.

The core migrations create the `vector` and `payload` columns as `json`. DB-specific drivers ship their own migrations (same filenames) with native column types (`VECTOR`, `vector`, `JSONB`, …). Publish the driver migrations **instead of** the core ones when using a driver:

```bash
# MySQL 9
php artisan vendor:publish --tag=embedding-mysql-migrations

# PostgreSQL
php artisan vendor:publish --tag=embedding-pgsql-migrations

# Oracle
php artisan vendor:publish --tag=embedding-oracle-migrations
```

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run tests
docker compose --profile php83 up
docker compose --profile php84 up
docker compose --profile php85 up
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
