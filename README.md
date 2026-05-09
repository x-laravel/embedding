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
- Similarity search is driver-based: PHP by default (works with any database), or native DB-level vector search via dedicated drivers for MySQL HeatWave, MariaDB 11.7+, PostgreSQL (pgvector), Oracle 26ai, SQL Server 2025, and Qdrant — see [Similarity Drivers](#similarity-drivers)

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- `laravel/ai ^0.6`

## Installation

```bash
composer require x-laravel/embedding
```

Run the migration:

```bash
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=embedding-config
```

## Setup

### 1. Single-slot model

For most use cases, return a string from `toEmbeddingText()` and list trigger fields in `$embeddable`:

```php
use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(): string
    {
        return $this->title . ' ' . $this->body;
    }
}
```

### 2. Multi-slot model

Return an array from `toEmbeddingText()` and use a nested `$embeddable` map to define which fields trigger each slot:

```php
class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    protected array $embeddable = [
        'title' => ['title'],
        'body'  => ['body'],
        'full'  => ['title', 'body'],
    ];

    public function toEmbeddingText(): string|array
    {
        return [
            'title' => $this->title,
            'body'  => $this->body,
            'full'  => $this->title . ' ' . $this->body,
        ];
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

### `embedding:generate`

```bash
php artisan embedding:generate                                # auto-discover models in app/Models
php artisan embedding:generate "App\Models\Post"              # missing embeddings, all slots
php artisan embedding:generate "App\Models\Post" --slot=title # specific slot only
php artisan embedding:generate "App\Models\Post" --limit=100  # at most 100 records per slot
php artisan embedding:generate "App\Models\Post" --chunk=500  # fetch records 500 at a time
php artisan embedding:generate "App\Models\Post" --sync       # generate inline instead of queueing
php artisan embedding:generate "App\Models\Post" --force      # regenerate all records, all slots
php artisan embedding:generate --dry-run                      # report counts, dispatch nothing
php artisan embedding:generate -v                             # verbose: show stack traces / discovery skips
```

When the model argument is omitted, the command scans `app/Models` (or `app/`) for classes implementing `HasEmbeddings`, asks for confirmation if more than one is found, and processes them sequentially. Failures are isolated per model and a summary is printed at the end.

### `embedding:clear`

Bulk-delete stored embeddings. Requires either a model class or `--all`.

```bash
php artisan embedding:clear "App\Models\Post"                 # all embeddings for Post
php artisan embedding:clear "App\Models\Post" --slot=title    # only the title slot for Post
php artisan embedding:clear --slot=title --all                # delete the title slot across every model
php artisan embedding:clear --all                             # truncate the entire embeddings table
php artisan embedding:clear "App\Models\Post" --chunk=500     # 500 rows per delete batch (progress bar)
php artisan embedding:clear "App\Models\Post" --force         # skip the confirmation prompt
php artisan embedding:clear "App\Models\Post" --dry-run       # report counts, delete nothing
```

### `embedding:clean`

Tidy up stale rows. By default deletes both **orphan** records (model class missing or model row no longer exists) and records whose `slot` is no longer defined in the model's `embeddingSlotMap()`.

```bash
php artisan embedding:clean                                   # delete orphans + invalid-slot records
php artisan embedding:clean --orphans-only                    # only remove orphans
php artisan embedding:clean --invalid-slots-only              # only remove records with unknown slots
php artisan embedding:clean --chunk=500                       # 500 rows per delete batch (progress bar)
php artisan embedding:clean --force                           # skip the confirmation prompt
php artisan embedding:clean --dry-run                         # report findings, delete nothing
```

## Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `EMBEDDING_DIMENSIONS` | `1536` | Vector size — must match your AI model's output |
| `EMBEDDINGS_DATABASE_CONNECTION` | `DB_CONNECTION` | Dedicated DB connection for embeddings |
| `EMBEDDINGS_DB_TABLE` | `embeddings` | Table name |
| `QUEUE_CONNECTION` | `sync` | Queue connection for the generation job |
| `EMBEDDING_QUEUE` | `embedding` | Queue name |
| `EMBEDDING_SIMILARITY_DRIVER` | `auto` | Force a specific similarity driver (`php`, or an installed DB driver) |

## Database

```
embeddings
├── id
├── embeddable_type   (polymorphic — Post, Article, etc.)
├── embeddable_id
├── slot              (varchar 64, default 'default')
├── vector            (json)
├── created_at
└── updated_at
                      unique: (embeddable_type, embeddable_id, slot)
```

The core migration creates the `vector` column as `json`. DB-specific drivers ship their own migration with a native vector column type (`VECTOR`, `vector`). Publish the driver migration **instead of** the core one when using a driver:

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
