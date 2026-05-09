# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`x-laravel/embedding` is a Laravel package that automatically generates and stores vector embeddings for Eloquent models using `laravel/ai`. When a model's embeddable fields change, a queued job calls `Embeddings::for([...])->generate()` and stores the result in a polymorphic `embeddings` table.

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

The embedding lifecycle is: **model saved → observer → job dispatched per slot → EmbeddingGenerator → VectorStore → DB record**.

```
EmbeddingObserver (saved/deleted/restored/forceDeleted)
    └─► model->slotsToEmbed(changedKeys) → ['title', 'full']
            └─► dispatch(GenerateModelEmbedding) × per slot
                    └─► EmbeddingGenerator::generate($model, $slot)
                            ├─► resolveText() → toEmbeddingText()[$slot]
                            ├─► fireModelEvent('embedding', $slot) + event(ModelEmbedding)
                            ├─► Embeddings::for([text])->generate()   ← laravel/ai
                            ├─► VectorStore::store($model, $vector, $slot)
                            └─► fireModelEvent('embedded', $slot) + event(ModelEmbedded)
```

**`EmbeddingGenerator`** (`src/EmbeddingGenerator.php`) is the single point where `laravel/ai` is called. Resolves `VectorStore` from the container for persistence. `resolveText()` handles both string and array returns from `toEmbeddingText()`.

**`VectorStore` contract** (`src/Contracts/VectorStore.php`) decouples storage from generation. Core binds `JsonVectorStore` by default. DB-specific drivers (MySQL, pgsql, Oracle) override this binding in their `register()` method.

**`JsonVectorStore`** (`src/Storage/JsonVectorStore.php`) is the default implementation — stores vectors as JSON via Eloquent `updateOrCreate`. Works with any DB driver (SQLite, MySQL 8, etc.).

**`Embeddable` trait** (`src/Concerns/Embeddable.php`) is the core. Key internals:
- `bootEmbeddable()` defers observer registration via `whenBooted` to avoid circular boot
- `embeddingSlotMap()` builds the slot→fields map from `$embeddable` + `#[EmbedOn]` attributes
- `slotsToEmbed(array $changedKeys)` returns which slots need re-embedding for the given field changes. Uses `wasRecentlyCreated && empty($changedKeys)` to seed all slots on insert (Eloquent does not call `syncChanges()` after insert, so `getChanges()` is empty for new records)
- `fireEmbeddingModelEvent(string $event, string $slot)` dispatches directly to the event dispatcher with `[$model, $slot]` payload so listeners can optionally receive the slot

**`EmbeddingObserver`** (`src/Observers/EmbeddingObserver.php`) handles `saved`, `deleted`, `restored`, `forceDeleted`. The `saved` handler skips soft-delete restores. `deleted`/`forceDeleted` call `$model->embeddings()->delete()` (MorphMany — all slots). `restored` re-embeds all slots from `embeddingSlotMap()`. `$syncingDisabledFor` is class-scoped static.

**`SimilarityManager`** (`src/SimilarityManager.php`) extends Laravel's `Manager`. Auto-detection: if a driver registered a name matching the DB connection driver (via `extend()`), it is used; otherwise falls back to `php`. Override with `EMBEDDING_SIMILARITY_DRIVER`.

## Driver System

DB-specific vector support lives in separate packages. Each driver:
1. Binds `VectorStore::class` in `register()` — overrides write/read storage
2. Extends `SimilarityManager` via `extend()` in `boot()` — registers similarity search
3. Ships its own migration with a native vector column

| Database | Package | Operations |
|----------|---------|------------|
| MySQL HeatWave | `x-laravel/embedding-mysql-driver` | `STRING_TO_VECTOR` write, `VECTOR_TO_STRING` read, `VEC_DISTANCE_COSINE` search |
| MariaDB 11.7+ | `x-laravel/embedding-mariadb-driver` | `Vec_FromText` write, `VEC_ToText` read, `VEC_Distance_Cosine` search |
| PostgreSQL | `x-laravel/embedding-pgsql-driver` | pgvector `<=>` search (JSON compat — no custom store needed) |
| Oracle 26ai | `x-laravel/embedding-oracle-driver` | `TO_VECTOR` / `VECTOR_DISTANCE` |
| SQL Server 2025 | `x-laravel/embedding-sqlsrv-driver` | `CAST(? AS VECTOR(n))` write, `CAST(vector AS NVARCHAR(MAX))` read, `VECTOR_DISTANCE('cosine', ...)` search |
| Qdrant | `x-laravel/embedding-qdrant-driver` | dual-write (SQL + Qdrant), `$vectorSearch` REST API search |

Core `Embedding` model (`src/Models/Embedding.php`) is intentionally DB-agnostic — no global scopes, no version checks. DB-specific scopes (e.g. `VECTOR_TO_STRING`) are added by drivers in their `boot()`.

## Model Requirements

Any model using the trait must implement `HasEmbeddings`. `toEmbeddingText()` may return a `string` (single default slot) or an `array` (multiple named slots):

```php
// Single slot
class Post extends Model implements HasEmbeddings
{
    use Embeddable;
    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(): string
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

## Soft Delete Behaviour

Controlled by `embedding.soft_delete` config (default `false`). Per-model override via `protected bool $keepEmbeddingOnSoftDelete`.

| Event | `false` (default) | `true` |
|-------|----------------------|----------------------|
| soft delete | all slot embeddings deleted | embeddings kept |
| restore | all slots regenerated | unchanged |
| force delete | all slot embeddings deleted | all slot embeddings deleted |

## Configuration

Key environment variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `EMBEDDING_DIMENSIONS` | `1536` | Vector size — must match AI model output |
| `EMBEDDINGS_DATABASE_CONNECTION` | `DB_CONNECTION` | Dedicated DB connection for embeddings |
| `EMBEDDINGS_DB_TABLE` | `embeddings` | Table name |
| `QUEUE_CONNECTION` | `sync` | Queue connection |
| `EMBEDDING_QUEUE` | `embedding` | Queue name |
| `EMBEDDING_SIMILARITY_DRIVER` | `auto` | Force a specific similarity driver |

Publish config: `php artisan vendor:publish --tag=embedding-config`

## Schema

The core migration creates `embeddings` with `vector JSON`. DB-specific drivers publish their own migration (same filename) with a native vector column type — publish the driver migration instead of the core one.

The `embeddings` table has a `slot VARCHAR(64) DEFAULT 'default'` column. The unique index is `(embeddable_type, embeddable_id, slot)` — one record per model per slot.

- Single-slot models use `slot = 'default'`
- `embedding(string $slot = 'default'): MorphOne` — relationship scoped to one slot
- `embeddings(): MorphMany` — all slot records for a model

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
```

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

`embedding:clear` requires either a model class or `--all` (the two cannot be combined). Without `--force` it prompts for confirmation. The `--all` + no-slot path uses `truncate` for speed; everything else uses a chunked `chunkById` + `whereIn DELETE` with a progress bar.

```bash
php artisan embedding:clean                                         # remove orphan + invalid-slot records
php artisan embedding:clean --orphans-only                          # only delete records whose model class is missing or whose row was deleted
php artisan embedding:clean --invalid-slots-only                    # only delete records whose slot is no longer in embeddingSlotMap()
php artisan embedding:clean --chunk=500                             # 500 rows per delete batch (progress bar)
php artisan embedding:clean --force                                 # skip confirmation
php artisan embedding:clean --dry-run                               # report findings, delete nothing
```

`embedding:clean` walks distinct `embeddable_type` values, classifies each as orphan (class missing or row deleted) or invalid-slot (slot not present in the model's `embeddingSlotMap()`), then deletes the union with a chunked progress bar. Models whose `embeddingSlotMap()` returns an empty array are skipped for the invalid-slot pass — we do not delete records for a model that simply has no slots defined.

## Horizon Tags

Each `GenerateModelEmbedding` job carries tags: `['embedding', 'slot:title', 'App\Models\Post:42']`.

## Testing Notes

- `Embeddings::fake()` is called in `TestCase::setUp()` — no real API calls.
- The fake returns a fresh random vector per call, so `assertNotEquals` on vectors works to verify a slot was re-embedded.
- To verify a slot was NOT re-embedded, compare `updated_at` timestamps rather than vectors.
- `tests/Models/` has fixture models including `PostMultiSlot` and `PostWithMultiSlotAttribute` for multi-slot scenarios.

## Critical Implementation Notes

- **`fireModelEvent` is `protected`** — the trait bypasses it with a direct dispatcher call: `static::$dispatcher->dispatch("eloquent.{$event}: ".static::class, [$this, $slot])`. Spreading `[$model, $slot]` as payload means listeners receive `($model, $slot)` — single-arg listeners still work since PHP ignores extra args.
- **`$embeddable` is NOT declared in the trait** — declaring it causes a PHP 8.2+ fatal error when a model also declares it.
- **`bootEmbeddable` uses `whenBooted`** — direct `static::observe()` in boot causes circular boot.
- **`slotsToEmbed` new-record detection** — Eloquent does not call `syncChanges()` after `INSERT`, so `getChanges()` returns `[]` for new records. The trait uses `wasRecentlyCreated && empty($changedKeys)` to trigger all slots on creation. When the same instance is later used for an update, `changedKeys` will be non-empty so the field-based path runs instead.
- **`laravel/ai` version is `^0.6`** — v0.1.x requires PHP `^8.4`; v0.6.x supports PHP `^8.3`.
- **`VectorStore` is bound in `register()`** — driver ServiceProviders must bind `VectorStore::class` in `register()`, not `boot()`, so it is available before `EmbeddingGenerator` is first resolved.
- **`Embedding` model is DB-agnostic** — do not add DB-specific global scopes or casts here. Drivers add their own scopes in `boot()` via `Embedding::addGlobalScope()`.

## Git Commits

Never create a commit unless the user explicitly requests it.
