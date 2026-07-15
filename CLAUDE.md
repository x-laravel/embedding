# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`x-laravel/embedding` is a Laravel package that automatically generates and stores vector embeddings for Eloquent models using `laravel/ai`. When a model's embeddable fields change, a queued job calls `Embeddings::for([...])->generate()` and stores the result in a polymorphic `embeddings` table. v2 adds a second, independent write path: models can publish scalar attributes as a **payload** into a polymorphic `embeddables` table (one row per entity), and similarity searches can filter on it at the database level via the `filter` parameter.

- **Package name:** `x-laravel/embedding` — **Namespace:** `XLaravel\Embedding`
- PHP `^8.3`, Laravel (illuminate) `^12.0|^13.0`, `laravel/ai ^0.6`

## Running Tests

```bash
# Build once per PHP version
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run all tests
docker compose --profile php83 up   # PHP 8.3
docker compose --profile php84 up   # PHP 8.4
docker compose --profile php85 up   # PHP 8.5

# Run a single test class (inside Docker)
docker compose --profile php83 run --rm php83 vendor/bin/phpunit --filter MultiSlotTest

# Run a single test method
docker compose --profile php83 run --rm php83 vendor/bin/phpunit --filter test_only_title_and_full_slots_regenerated_when_title_changes
```

CI runs PHP 8.3–8.5 × Laravel 12–13 (6 combinations) via `.github/workflows/tests.yml`.

## Architecture

The embedding lifecycle is: **model saved → observer → job dispatched per slot → EmbeddingGenerator → VectorStore → DB record**. The payload lifecycle is a parallel, fully independent path: **model saved → observer → SyncModelPayload job → PayloadStore → embeddables row**.

```
EmbeddingObserver (saved/deleted/restored/forceDeleted)
    ├─► model->slotsToEmbed(changedKeys) → ['title', 'full']
    │       └─► dispatch(GenerateModelEmbedding) × per slot     ← queue: embedding.generate
    │               └─► EmbeddingGenerator::generate($model, $slot)
    │                       ├─► resolveText() → toEmbeddingText($slot)
    │                       ├─► fireModelEvent('embedding', $slot) + event(ModelEmbedding)
    │                       ├─► Embeddings::for([text])->generate()   ← laravel/ai
    │                       ├─► VectorStore::store($model, $vector, $slot)
    │                       └─► fireModelEvent('embedded', $slot) + event(ModelEmbedded)
    │
    └─► model->payloadFieldsChanged(changedKeys) || (wasRecentlyCreated && hasEmbeddingPayload())
            └─► dispatch(SyncModelPayload)                      ← queue: embedding.sync-payload
                    └─► resolveEmbeddingPayload() → PayloadStore::upsert($model, $payload)
```

The vector path is completely unaware of the payload — `GenerateModelEmbedding` / `EmbeddingGenerator` never touch it. `SyncModelPayload` is the **single writer** for `embeddables` rows, so no payload race between slot jobs can exist.

**`EmbeddingGenerator`** (`src/EmbeddingGenerator.php`) is the single point where `laravel/ai` is called. Resolves `VectorStore` from the container for persistence. `resolveText()` validates the requested slot against `embeddingSlotMap()` (undeclared slots throw; `'default'` stays callable on models with an empty slot map), then calls `toEmbeddingText($slot)` — only the requested slot's text is built.

**`VectorStore` contract** (`src/Contracts/VectorStore.php`) decouples storage from generation. Core binds `JsonVectorStore` by default. DB-specific drivers (MySQL, pgsql, Oracle) override this binding in their `register()` method.

**`JsonVectorStore`** (`src/Storage/JsonVectorStore.php`) is the default implementation — stores vectors as JSON via Eloquent `updateOrCreate`. Works with any DB driver (SQLite, MySQL 8, etc.).

**`Embeddable` trait** (`src/Concerns/Embeddable.php`) is the core. Key internals:
- `bootEmbeddable()` defers observer registration via `whenBooted` to avoid circular boot
- `embeddingSlotMap()` builds the slot→fields map from `$embeddable` + `#[EmbedOn]` attributes
- `slotsToEmbed(array $changedKeys)` returns which slots need re-embedding for the given field changes. Uses `wasRecentlyCreated && empty($changedKeys)` to seed all slots on insert (Eloquent does not call `syncChanges()` after insert, so `getChanges()` is empty for new records)
- `fireEmbeddingModelEvent(string $event, string $slot)` dispatches directly to the event dispatcher with `[$model, $slot]` payload so listeners can optionally receive the slot

**`EmbeddingObserver`** (`src/Observers/EmbeddingObserver.php`) handles `saved`, `deleted`, `restored`, `forceDeleted`. The `saved` handler skips soft-delete restores, dispatches vector jobs per slot and (independently) a `SyncModelPayload` job when payload fields changed. `deleted`/`forceDeleted` call `$model->embeddings()->delete()` (MorphMany — all slots) and `PayloadStore::delete($model)`; in soft-delete keep mode both survive. `restored` re-embeds all slots from `embeddingSlotMap()` and re-syncs the payload (only in the `!keep` branch — in keep mode the payload row was never deleted). `$syncingDisabledFor` is class-scoped static; `disableEmbedding()` / `withoutEmbedding()` suppress payload dispatch too.

**`PayloadStore` contract** (`src/Contracts/PayloadStore.php`) — `upsert(Model $model, array $payload): void` + `delete(Model $model): void`. Core binds `DatabasePayloadStore` (`src/Storage/DatabasePayloadStore.php`), which writes via `Models\Embeddable::upsert()` (race-safe against the unique index; `firstOrCreate` is NOT used; payload is `json_encode`d manually because `upsert()` bypasses Eloquent casts). The Qdrant driver decorates/overrides this binding for dual-write.

**`SyncModelPayload`** (`src/Jobs/SyncModelPayload.php`) carries the model, resolves `resolveEmbeddingPayload()` at run time, calls `PayloadStore::upsert()`. Same queue connection as vector jobs but its own queue name (`embedding.queue.sync_payload`) to avoid head-of-line blocking behind slow AI calls. Horizon tags: `['embedding', 'payload', Model:id]`.

**`SearchRequest`** (`src/Contracts/SearchRequest.php`) — `final readonly` DTO carrying `vector, limit=10, threshold=0.0, ?ids=null, slot='default', ?filter=null`. `SimilarityDriver::search(Model $prototype, SearchRequest $request): Collection` is the v2 signature (the old 6-parameter form is gone). `PhpDriver` translates `filter` to a `whereExists` subquery against `embeddables` on the same connection — equality, `whereIn` for array values, AND across keys.

**`SimilarityManager`** (`src/SimilarityManager.php`) extends Laravel's `Manager`. Auto-detection: if a driver registered a name matching the DB connection driver (via `extend()`), it is used; otherwise falls back to `php`. Override with `EMBEDDING_SIMILARITY_DRIVER`.

**`Reranker`** (`src/Reranker.php`) is the orchestrator for two-stage retrieval. After `similarTo()` returns candidate models, the `Eloquent\Collection::rerankWithScores()` macro (registered in `EmbeddingServiceProvider::registerCollectionMacros()`) forwards their texts to `laravel/ai`'s `Reranking::of(...)->rerank()` and sets a `rerank_score` attribute on each model, sorted by score descending. Provider/model selection is delegated entirely to `laravel/ai` via `ai.default_for_reranking` — the package adds no second config layer. Empty and single-item collections short-circuit (no API call). Lives at the top level (peer of `EmbeddingGenerator`), **not** under `src/Similarity/` — that namespace is reserved for things that implement the `SimilarityDriver` contract.

## Driver System

DB-specific vector support lives in separate packages (all on `x-laravel/embedding ^2.0`). Each driver:
1. Binds `VectorStore::class` in `register()` — overrides write/read storage
2. Extends `SimilarityManager` via `extend()` in `boot()` — registers similarity search
3. Ships its own migrations (native vector column + `embeddables` with a native JSON type) — same filenames as core, so the driver files win when published
4. Translates the payload `filter` to native JSON SQL (or Qdrant payload filters)

| Database | Package | Operations | Payload filter |
|----------|---------|------------|----------------|
| MySQL HeatWave | `x-laravel/embedding-mysql-driver` | `STRING_TO_VECTOR` write, `VECTOR_TO_STRING` read, `VEC_DISTANCE_COSINE` search | `payload->'$.x'` (JSON path) |
| MariaDB 11.7+ | `x-laravel/embedding-mariadb-driver` | `Vec_FromText` write, `VEC_ToText` read, `VEC_Distance_Cosine` search | `JSON_VALUE(payload, '$.x')` |
| PostgreSQL | `x-laravel/embedding-pgsql-driver` | pgvector `<=>` search (JSON compat — no custom store needed) | `payload->>'x'` on `JSONB` |
| Oracle 26ai | `x-laravel/embedding-oracle-driver` | `TO_VECTOR` / `VECTOR_DISTANCE` | `JSON_VALUE` with mandatory `RETURNING` + type guards |
| SQL Server 2025 | `x-laravel/embedding-sqlsrv-driver` | `CAST(? AS VECTOR(n))` write, `CAST(vector AS NVARCHAR(MAX))` read, `VECTOR_DISTANCE('cosine', ...)` search | `JSON_VALUE(payload, '$.x')` + `TRY_CAST` for numbers |
| Qdrant | `x-laravel/embedding-qdrant-driver` | dual-write (SQL + Qdrant), `$vectorSearch` REST API search | Qdrant `filter: {must: [{key, match}]}` + `PayloadStore` dual-write |

Core `Embedding` model (`src/Models/Embedding.php`) is intentionally DB-agnostic — no global scopes, no version checks. DB-specific scopes (e.g. `VECTOR_TO_STRING`) are added by drivers in their `boot()`.

## Model Requirements

Any model using the trait must implement `HasEmbeddings`. `toEmbeddingText(string $slot = 'default'): string` returns the text for **one** slot — the requested one. Single-slot models ignore the argument; multi-slot models branch on it (only the requested slot's text is built, never all slots):

```php
// Single slot
class Post extends Model implements HasEmbeddings
{
    use Embeddable;
    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->title . ' ' . $this->body;
    }
}

// Multiple slots
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

## Defining Which Fields Trigger Embedding

Two approaches — they **merge** in `embeddingSlotMap()`.

**Option 1 — `$embeddable` property:**
```php
// Flat list → single 'default' slot
protected array $embeddable = ['title', 'body'];   // [] = never, ['*'] = always

// Nested map → multiple named slots
protected array $embeddable = [
    'title' => ['title'],
    'body'  => ['body'],
    'full'  => ['title', 'body'],
];
```

Detection: `array_is_list($embeddable)` → flat; associative → multi-slot.

**Option 2 — `#[EmbedOn]` PHP attribute** (`src/Attributes/EmbedOn.php`):
```php
// Single field, default slot
#[EmbedOn('title')]

// Multiple fields, default slot
#[EmbedOn(['title', 'body'])]

// Multiple attributes for named slots (IS_REPEATABLE)
#[EmbedOn('title', slot: 'title')]
#[EmbedOn('body', slot: 'body')]
#[EmbedOn(['title', 'body'], slot: 'full')]
class Post extends Model implements HasEmbeddings { ... }
```

**Granular re-embedding:** When `title` changes, only slots whose field list contains `title` are re-embedded (e.g. `title` and `full`, but not `body`).

## Payload — Filterable Metadata

`#[EmbedPayload]` (`src/Attributes/EmbedPayload.php`, single-use, NOT repeatable) declares which attributes are copied into the `embeddables.payload` JSON column for filtered search:

```php
#[EmbedPayload(['province_id', 'category_id'])]        // explicit list — strict (non-scalar throws)
#[EmbedPayload('*')]                                   // wildcard — all attributes minus PK + $hidden
#[EmbedPayload('*', except: ['internal_notes'])]       // wildcard with exclusions
```

Resolution (`resolveEmbeddingPayload()` in the trait):
- Explicit list: values via `$model->getAttribute($field)` (accessors included); scalar (int/string/bool/null) or scalar-array only, otherwise `InvalidArgumentException`.
- Wildcard: expands over the instance's attribute keys, **lenient** — date casts serialize via `serializeDate()`, backed enums via `->value`, still-incompatible values are skipped instead of throwing. A model with no `EmbedPayload` at all is NOT treated as wildcard (deliberate — silent PII copying risk).
- `toEmbeddingPayload(): array` (duck-typed via `method_exists` — NOT part of `HasEmbeddings`) merges over the field-derived values; **the method wins**.
- The trait caches the attribute instance per class (`$embedPayloadCache`, flushed via `flushEmbeddingPayloadFieldsCache()`).

Dirty detection uses `embeddingPayloadFields()` (attribute-derived column list) via `payloadFieldsChanged($changedKeys)` — values computed in `toEmbeddingPayload()` from non-column sources are invisible to it; users call `$model->syncEmbeddingPayload()` manually (works even while syncing is disabled; no-op for payload-less models).

Search-side: `filter` semantics are deliberately minimal — **equality + IN (array value) + AND (multiple keys)**. No range/OR/nested. Type-strict (`34` ≠ `"34"`). Records without an `embeddables` row never match a filtered search. `where` (closure) and `filter` can be combined — both apply, no smart merging. Guidance: high-cardinality/indexable constraint → `filter`; one-off/complex/small-set → `where`.

## Soft Delete Behaviour

Controlled by `embedding.soft_delete` config (default `false`). Per-model override via `protected bool $keepEmbeddingOnSoftDelete`.

| Event | `false` (default) | `true` |
|-------|----------------------|----------------------|
| soft delete | all slot embeddings + payload row deleted | embeddings + payload kept |
| restore | all slots regenerated + payload re-synced | unchanged |
| force delete | all slot embeddings + payload row deleted | all slot embeddings + payload row deleted |

## Configuration

Key environment variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `EMBEDDING_DIMENSIONS` | `1536` | Vector size — must match AI model output |
| `EMBEDDINGS_DATABASE_CONNECTION` | `DB_CONNECTION` | Dedicated DB connection for embeddings |
| `EMBEDDINGS_DB_TABLE` | `embeddings` | Vector table name (config key: `database.embeddings_table`) |
| `EMBEDDABLES_DB_TABLE` | `embeddables` | Payload table name (config key: `database.embeddables_table`) |
| `QUEUE_CONNECTION` | `sync` | Queue connection (shared by both job types) |
| `EMBEDDING_GENERATE_QUEUE` | `embedding.generate` | Queue for vector jobs (config key: `queue.generate`) |
| `EMBEDDING_SYNC_PAYLOAD_QUEUE` | `embedding.sync-payload` | Queue for payload jobs (config key: `queue.sync_payload`) |
| `EMBEDDING_MAX_LENGTH` | `null` | Truncate input text before the API call |
| `EMBEDDING_SIMILARITY_DRIVER` | `auto` | Force a specific similarity driver |

Workers must listen to both queues, payload first: `queue:work --queue=embedding.sync-payload,embedding.generate`. SQS queue names cannot contain dots — override both envs there.

Publish config: `php artisan vendor:publish --tag=embedding-config`. **Migrations are NOT auto-loaded** — they must be published (`--tag=embedding-migrations`, or the driver's tag) before `migrate`.

## Schema

Two tables, matched by the `(embeddable_type, embeddable_id)` morph pair (no FK):

- `embeddings` — `id, morphs('embeddable'), slot VARCHAR(64) DEFAULT 'default', vector JSON, timestamps`. Unique: `(embeddable_type, embeddable_id, slot)` — one record per model per slot.
- `embeddables` — `id, morphs('embeddable'), payload JSON, timestamps`. Unique: `(embeddable_type, embeddable_id)` — **one payload row per entity**, created only for models that define a payload. The column is named `payload`, NOT `attributes` (collides with Eloquent's internal `$attributes`).

The core migrations use `json` columns. DB-specific drivers publish their own migrations (same filenames — the migrator dedupes by name) with native column types — publish the driver migrations instead of the core ones.

- Single-slot models use `slot = 'default'`
- `embedding(string $slot = 'default'): MorphOne` — relationship scoped to one slot
- `embeddings(): MorphMany` — all slot records for a model
- `Models\Embeddable` (`src/Models/Embeddable.php`) — payload row model; name-collides with `Concerns\Embeddable` (deliberate) — use an import alias or FQCN where both appear
- `Embedding::payloadRecord(): ?Models\Embeddable` — plain method, not a relationship (composite morph key)

## Key Public API

```php
$model->embed('title');               // dispatch async job for specific slot
$model->embed();                      // dispatch for 'default' slot
$model->embedSync('full');            // synchronous, specific slot
$model->hasEmbedding('body'): bool
$model->embedding('title'): MorphOne  // scoped relationship
$model->embeddings(): MorphMany       // all slots

Post::withoutEmbedding(fn() => ...);
Post::disableEmbedding();
Post::enableEmbedding();

// Similarity search — all accept slot: named arg
Post::similarTo($vector, limit: 10, threshold: 0.8, slot: 'title');
Post::similarTo($vector, where: fn($q) => $q->where('status', 'published'), slot: 'full');
Post::similarToText('web framework', limit: 10, slot: 'body');
Post::rankByRelevance($collection, 'web framework', slot: 'full');
$post->similarityTo($otherPost, slot: 'title');   // float score
$post->mostSimilar(limit: 5, slot: 'full');

// Payload filtering — similarTo / similarToText / mostSimilar only
// (similarityTo and rankByRelevance take no filter — meaningless for pairwise/rerank)
Venue::similarTo($vector, limit: 300, slot: 'name', filter: ['province_id' => 34]);
Venue::similarToText('kebap', filter: ['category_id' => [3, 7], 'active' => true]);
$venue->mostSimilar(limit: 5, filter: ['province_id' => 34]);

// Payload write path
$venue->syncEmbeddingPayload();          // synchronous upsert (no-op without payload definition)
$venue->hasEmbeddingPayload(): bool
$venue->resolveEmbeddingPayload(): array // fields + toEmbeddingPayload() merge
$venue->embeddingPayloadFields(): array  // attribute-derived column list (dirty detection)
$venue->payloadFieldsChanged(['province_id']): bool

// Reranking — Eloquent Collection macro on top of similarTo() output
Post::similarTo($vector, limit: 50)
    ->rerankWithScores('query', take: 5, threshold: 0.5, field: null, slot: 'default');
$post->rerank_score;  // float, set by rerankWithScores alongside similarity_score

// Direct service access (DI / manually-fetched collections)
app(\XLaravel\Embedding\Reranker::class)->rerank($candidates, query: 'q', take: 5);

// Slot introspection
$model->embeddingSlotMap(): array    // ['title' => ['title'], 'full' => ['title','body']]
$model->slotsToEmbed(['title']): array  // ['title', 'full']
```

`threshold` defaults to `0.0` (no filtering). All similarity methods set `similarity_score` on returned models. Driver (`php`, `pgsql`, `mysql`, `oracle`, …) is resolved automatically if the matching driver package is installed.

## Model Events

```php
// Static helpers — callback receives ($model, $slot)
static::onEmbedding(fn($model, $slot) => ...);  // before generation
static::onEmbedded(fn($model, $slot) => ...);   // after record saved

// Observer class — same signature
public function embedding(Post $post, string $slot): void { ... }
public function embedded(Post $post, string $slot): void { ... }
```

Laravel events (`ModelEmbedding` / `ModelEmbedded`) each carry `$model`, `$embedding` (ModelEmbedded only), and `$slot`.

## Artisan Command

```bash
php artisan embedding:generate                                      # auto-discover models in app/Models
php artisan embedding:generate "App\Models\Post"                    # missing slots only
php artisan embedding:generate "App\Models\Post" --slot=title       # specific slot only
php artisan embedding:generate "App\Models\Post" --limit=100        # at most 100 records per slot
php artisan embedding:generate "App\Models\Post" --chunk=500        # fetch records 500 at a time
php artisan embedding:generate "App\Models\Post" --sync             # generate inline (no queue)
php artisan embedding:generate "App\Models\Post" --force            # regenerate all
php artisan embedding:generate --dry-run                            # report counts, dispatch nothing
php artisan embedding:generate -v                                   # show stack traces / discovery skips
php artisan embedding:generate --payload-only                       # backfill embeddables rows — no AI, no vectors
```

`--payload-only` is idempotent (missing-row query; cross-connection falls back to pluck+reject), errors when combined with `--slot`, `--force` refreshes existing rows, `--sync` upserts inline via `syncEmbeddingPayload()` instead of dispatching `SyncModelPayload`. Payload-less models warn and count 0.

Without `--slot`, all defined slots are processed independently (each has its own "missing" query). When the model argument is omitted, the command scans `app/Models` (or `app/`) for any class implementing `HasEmbeddings`, prompts for confirmation, and processes them sequentially — failures on one model do not stop the others; a summary list is printed at the end.

```bash
php artisan embedding:clear "App\Models\Post"                       # delete every embedding for Post
php artisan embedding:clear "App\Models\Post" --slot=title          # delete only the title slot
php artisan embedding:clear --slot=title --all                      # delete the title slot across every model
php artisan embedding:clear --all                                   # truncate the entire table
php artisan embedding:clear "App\Models\Post" --chunk=500           # 500 rows per delete batch (progress bar)
php artisan embedding:clear "App\Models\Post" --force               # skip confirmation
php artisan embedding:clear "App\Models\Post" --dry-run             # report counts, delete nothing
```

`embedding:clear` requires either a model class or `--all` (the two cannot be combined). Without `--force` it prompts for confirmation. The `--all` + no-slot path truncates **both** tables for speed; everything else uses a chunked `chunkById` + `whereIn DELETE` with a progress bar. Slot-scoped (`--slot`) clears never touch `embeddables` — the payload is entity-level. Messages append "... and N payload record(s)" only when payload rows are involved.

```bash
php artisan embedding:clean                                         # remove orphan + invalid-slot + stale payload records
php artisan embedding:clean --orphans-only                          # only delete records whose model class is missing or whose row was deleted
php artisan embedding:clean --invalid-slots-only                    # only delete records whose slot is no longer in embeddingSlotMap()
php artisan embedding:clean --payload-only                          # only delete stale embeddables rows
php artisan embedding:clean --chunk=500                             # 500 rows per delete batch (progress bar)
php artisan embedding:clean --force                                 # skip confirmation
php artisan embedding:clean --dry-run                               # report findings, delete nothing
```

`embedding:clean` walks distinct `embeddable_type` values, classifies each as orphan (class missing or row deleted) or invalid-slot (slot not present in the model's `embeddingSlotMap()`), then deletes the union with a chunked progress bar. Models whose `embeddingSlotMap()` returns an empty array are skipped for the invalid-slot pass — we do not delete records for a model that simply has no slots defined. The orphan scan also covers `embeddables` (class missing / row deleted / model no longer defines a payload). The three `--*-only` flags are mutually exclusive. Soft-deleted rows count as "exists" via a Query Builder subquery (keep mode preserves the payload). Output units are "record(s)" — the deletion set is mixed.

```bash
php artisan embedding:status                                        # full report (configuration, coverage, health, storage)
php artisan embedding:status "App\Models\Post"                      # restrict to one model
php artisan embedding:status "App\Models\Post" --slot=title         # restrict to one slot
php artisan embedding:status --json                                 # machine-readable output
```

`embedding:status` is read-only. It prints five sections (the four below plus **Payload** — models with payload definitions, `embeddables` row count, embedded entities missing a payload row (counted per entity via distinct `embeddable_id`, not per slot; both tables live on the embedding connection so the `whereNotExists` never crosses connections), with a `embedding:generate --payload-only` backfill hint in human output and a `payload` block in JSON): **Configuration** (a single Setting / Value / Detail / Note table covering similarity driver, vector dimensions, DB / queue connections, plus the resolved AI Embedding/Rerank Provider + Model — pulled from `laravel/ai` via `Ai::fakeableEmbeddingProvider($name)->defaultEmbeddingsModel()` and the rerank counterpart; `n/a` when unconfigured or when the provider class throws), **Model Coverage** (per-slot Records / Embedded / Coverage table — coverage uses the same `whereDoesntHave('embeddings', …)` logic as `embedding:generate`'s "missing" pass), **Health** (orphan and invalid-slot counts derived from `CleanCommand` queries, plus `Embedding::count()`), and **Storage** (driver-specific bytes via the `VectorStoreMetrics` contract). The JSON output keeps `configuration` and `ai` as separate top-level blocks so monitoring scripts that already parse them are unaffected. Any metric the contract cannot supply is rendered as `n/a`; the command never fails because of a missing storage figure.

`VectorStoreMetrics` (`src/Contracts/VectorStoreMetrics.php`) is the read-side counterpart of `VectorStore`. `snapshot()` returns `['rows' => int, 'bytes' => int|null, 'data_bytes' => int|null, 'index_bytes' => int|null]` — `rows` is always an int, byte fields are `int|null` (null = driver cannot supply). The core package binds `JsonVectorStoreMetrics` by default — it returns `Embedding::count()` for `rows` and `null` for every byte field. DB driver packages override the binding in their `register()` (`MysqlVectorStoreMetrics`, `PgsqlVectorStoreMetrics`, …) to add native byte figures. When `snapshot()` throws, `embedding:status` silently falls back to `n/a` and exits 0; passing `-v` surfaces the underlying exception message. Programmatic callers can call `app(VectorStoreMetrics::class)->snapshot()` directly — the default binding guarantees a result.

## Horizon Tags

Each `GenerateModelEmbedding` job carries tags: `['embedding', 'slot:title', 'App\Models\Post:42']`. Each `SyncModelPayload` job carries: `['embedding', 'payload', 'App\Models\Post:42']`.

## Testing Notes

- `Embeddings::fake()` is called in `TestCase::setUp()` — no real API calls.
- The fake returns a fresh random vector per call, so `assertNotEquals` on vectors works to verify a slot was re-embedded.
- To verify a slot was NOT re-embedded, compare `updated_at` timestamps rather than vectors.
- `tests/Fixtures/Models/` has fixture models including `PostMultiSlot` and `PostWithMultiSlotAttribute` for multi-slot scenarios, and the `VenueWithPayload*` family (attribute-based, method-based, mixed, wildcard, multi-slot, soft-delete) for payload scenarios.
- `Embeddings::assertNothingGenerated()` is unreliable across a test — the Ai manager accumulates records for the whole test and a second `fake()` call does not reset them; use a counting closure fake instead.
- Vector "not regenerated" can be asserted by vector equality — the fake returns a fresh random vector per call, so equality proves no regeneration.

## Critical Implementation Notes

- **`fireModelEvent` is `protected`** — the trait bypasses it with a direct dispatcher call: `static::$dispatcher->dispatch("eloquent.{$event}: ".static::class, [$this, $slot])`. Spreading `[$model, $slot]` as payload means listeners receive `($model, $slot)` — single-arg listeners still work since PHP ignores extra args.
- **`$embeddable` is NOT declared in the trait** — declaring it causes a PHP 8.2+ fatal error when a model also declares it.
- **`bootEmbeddable` uses `whenBooted`** — direct `static::observe()` in boot causes circular boot.
- **`slotsToEmbed` new-record detection** — Eloquent does not call `syncChanges()` after `INSERT`, so `getChanges()` returns `[]` for new records. The trait uses `wasRecentlyCreated && empty($changedKeys)` to trigger all slots on creation. When the same instance is later used for an update, `changedKeys` will be non-empty so the field-based path runs instead.
- **`laravel/ai` version is `^0.6`** — v0.1.x requires PHP `^8.4`; v0.6.x supports PHP `^8.3`.
- **`VectorStore` is bound in `register()`** — driver ServiceProviders must bind `VectorStore::class` in `register()`, not `boot()`, so it is available before `EmbeddingGenerator` is first resolved.
- **`Embedding` model is DB-agnostic** — do not add DB-specific global scopes or casts here. Drivers add their own scopes in `boot()` via `Embedding::addGlobalScope()`.
- **Reranker macro name is `rerankWithScores`, not `rerank`** — `laravel/ai` already registers `Illuminate\Support\Collection::rerank()` (a more general "rerank by field/closure" macro that does not set scores on the model). To avoid collision and keep both available, our macro is on Eloquent Collection with the explicit `rerankWithScores` name.
- **Reranker has no provider/model config** — selection is delegated to `laravel/ai` via `ai.default_for_reranking`. The package intentionally does not expose a parallel `embedding.rerank.provider` / `.model` config because two override paths cause drift.
- **Reranker short-circuits empty and single-item collections** — no provider API call is made when there is nothing to rank against. Empty collections return as-is. Single-item collections receive `rerank_score = 1.0` so downstream code can rely on the attribute always being present after `rerankWithScores()`.
- **`rerank_score` follows the same pattern as `similarity_score`** — set via `setAttribute()`, automatically serialized through `toArray()`/`toJson()`. The package does not impose response shape (no Resource sinks, no nesting); applications format as needed.
- **`Reranking::fake()` for tests** — `laravel/ai` v0.6+ ships `Reranking::fake(Closure|array)` and assertion helpers (`assertReranked`, `assertNothingReranked`). Pass a `Closure(RerankingPrompt $prompt)` returning `RankedDocument[]` for deterministic per-test responses.
- **Single-writer principle for payload** — only `SyncModelPayload` (and the synchronous `syncEmbeddingPayload()` helper) write `embeddables` rows. Vector jobs (`GenerateModelEmbedding` / `EmbeddingGenerator`) never touch the payload — this is what makes stale-payload races structurally impossible.
- **Payload column is named `payload`, never `attributes`** — `attributes` collides with Eloquent's internal `$attributes` property.
- **`Models\Embeddable` vs `Concerns\Embeddable`** — deliberate name collision; use an import alias (`use XLaravel\Embedding\Models\Embeddable as EmbeddablePayload;`) or FQCN in files where both appear.
- **`toEmbeddingPayload()` is duck-typed** — intentionally NOT part of `HasEmbeddings` (payload is optional); checked via `method_exists`.
- **`DatabasePayloadStore` uses `Embeddable::upsert()`** — race-safe against the unique index (`firstOrCreate` is NOT used); the payload is `json_encode`d manually because `upsert()` bypasses Eloquent casts.
- **Threshold cutoff only when `threshold > 0.0`** — cosine distance can exceed 1.0 (anti-correlated vectors); `threshold: 0.0` must return everything per the `SearchRequest` contract. Drivers must not apply an unconditional distance cutoff.
- **Oracle `JSON_VALUE` needs `RETURNING`** — the default `VARCHAR2` return implicitly converts numbers and strings into each other; the Oracle driver adds `RETURNING NUMBER` plus `.type()` guards for type-strict filters.
- **Wildcard payload is opt-in** — a model without any `#[EmbedPayload]` is never treated as wildcard (silent PII copying risk).

## Git Commits

Never create a commit unless the user explicitly requests it.
