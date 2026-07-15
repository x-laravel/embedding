# Changelog

All notable changes to `x-laravel/embedding` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.0 - 2026-07-16

v2 introduces **payload filtering**: models can publish a set of scalar attributes ("payload") into a second `embeddables` table, and similarity searches can filter on them at the database level via the new `filter` parameter — no post-query PHP filtering, no JOIN against the application tables.

### Added

- `embeddables` table — **one row per entity** holding a JSON `payload` column, matched to `embeddings` by the `(embeddable_type, embeddable_id)` morph pair (no FK). New Eloquent model `XLaravel\Embedding\Models\Embeddable`, configurable via `embedding.database.embeddables_table` / `EMBEDDABLES_DB_TABLE`.
- `#[EmbedPayload]` attribute (single-use, not repeatable):
  - `#[EmbedPayload(['province_id', 'category_id'])]` — explicit field list (strict: non-scalar values throw).
  - `#[EmbedPayload('*')]` / `#[EmbedPayload('*', except: ['secret'])]` — wildcard over the instance's attributes, excluding the primary key, `$hidden`, and the `except` list (lenient: dates serialize via `serializeDate()`, backed enums via `->value`, incompatible values are skipped).
- `toEmbeddingPayload(): array` support — duck-typed (not part of `HasEmbeddings`), merged over the attribute-derived fields; **the method wins** on key conflicts.
- `filter` parameter (`?array $filter = null`) on `similarTo()`, `similarToText()` and `mostSimilar()`. Semantics are intentionally minimal: **equality, IN (array value), AND (multiple keys)**. Records without a payload row never match a filtered search.
- `SearchRequest` DTO (`XLaravel\Embedding\Contracts\SearchRequest`) — carries `vector`, `limit`, `threshold`, `ids`, `slot`, `filter`.
- `PayloadStore` contract + `DatabasePayloadStore` (race-safe `upsert()` against the unique index; deletes on model delete).
- `SyncModelPayload` job — the **single writer** for payload rows, dispatched independently of the vector jobs on its own queue (`embedding.queue.sync_payload`, default `embedding.sync-payload`) so fast DB upserts never wait behind slow AI calls.
- `$model->syncEmbeddingPayload()` — synchronous payload upsert helper (no-op for models without payload definitions; works even while embedding syncing is disabled).
- Trait helpers: `embeddingPayloadFields()`, `hasEmbeddingPayload()`, `resolveEmbeddingPayload()`, `payloadFieldsChanged()`, `flushEmbeddingPayloadFieldsCache()`.
- `Embedding::payloadRecord()` — convenience accessor returning the entity's `Models\Embeddable` row (plain method, not an Eloquent relationship — the morph pair is a composite key).
- CLI split into two namespaces mirroring the two write paths, plus umbrella commands:
  - `embedding:vector:generate` / `embedding:vector:clear` / `embedding:vector:clean` / `embedding:vector:status` — vector-side only, never touch `embeddables`. `vector:clear` keeps the `--slot` option; `vector:clean` keeps `--orphans-only` / `--invalid-slots-only`.
  - `embedding:payload:sync` — backfills `embeddables` rows without touching the AI provider or vectors; idempotent, honours `--dry-run`, `--force` refreshes existing rows, `--sync` upserts inline.
  - `embedding:payload:clear` / `embedding:payload:clean` / `embedding:payload:status` — payload-side only, never touch `embeddings`. `payload:clean` removes stale rows (class missing / row deleted / model no longer defines a payload); `payload:status` reports per-model payload coverage, stale rows and embedded entities missing a payload row.
  - `embedding:clear` / `embedding:clean` (umbrellas) — operate on **both** tables for full-reset / full-cleanup. `embedding:clear` takes no `--slot` (payload is entity-level); `embedding:clean` takes no `--*-only` filters.

### Changed

- **Breaking:** `HasEmbeddings::toEmbeddingText()` is now `toEmbeddingText(string $slot = 'default'): string` — the `string|array` return is gone. The model builds **only the requested slot's text**; multi-slot models branch on `$slot` (e.g. `match`) instead of returning every slot's text on every call. `EmbeddingGenerator` validates the requested slot against `embeddingSlotMap()` and rejects undeclared slots.
- **Breaking:** `SimilarityDriver::search()` is now `search(Model $prototype, SearchRequest $request): Collection` — the old 6-parameter signature is removed. Custom drivers must be updated.
- **Breaking:** migrations are no longer auto-loaded (`loadMigrationsFrom` removed). Publish them before migrating: `php artisan vendor:publish --tag=embedding-migrations` (or the driver package's tag when using a DB driver).
- **Breaking:** queue configuration split. `EMBEDDING_QUEUE` (default `embedding`) is replaced by `EMBEDDING_GENERATE_QUEUE` (`embedding.queue.generate`, default `embedding.generate`) for vector jobs plus `EMBEDDING_SYNC_PAYLOAD_QUEUE` (`embedding.queue.sync_payload`, default `embedding.sync-payload`) for payload jobs. Workers should listen to both, payload first: `php artisan queue:work --queue=embedding.sync-payload,embedding.generate`. SQS queue names cannot contain dots — override both envs with hyphenated names on SQS.
- **Breaking:** config key `embedding.database.table` renamed to `embedding.database.embeddings_table` (env `EMBEDDINGS_DB_TABLE` unchanged).
- **Breaking:** `embedding:generate` and `embedding:status` are removed — use `embedding:vector:generate` / `embedding:vector:status` (and `embedding:payload:sync` / `embedding:payload:status` for the payload side). `embedding:clear` / `embedding:clean` remain but as umbrella commands over both tables: `embedding:clear` no longer accepts `--slot` (use `embedding:vector:clear --slot=...`) and `embedding:clean` no longer accepts `--orphans-only` / `--invalid-slots-only` / `--payload-only` (use the namespaced clean commands).
- All six driver packages (`mysql`, `mariadb`, `pgsql`, `oracle`, `sqlsrv`, `qdrant`) require `x-laravel/embedding ^2.0`, adopt the `SearchRequest` signature, ship their own `create_embeddables_table` migration (same filename as core — the driver file wins) and translate `filter` to native JSON SQL / Qdrant payload filters.

### Notes

- Choosing between `where` and `filter`: use `filter` for indexed / high-cardinality constraints stored in the payload; use the `where` closure for ad-hoc or complex Eloquent constraints against the model's own table. When both are given, both apply (no smart merging).
- Payload values are limited to scalars (int / string / bool / null) or arrays of scalars — nested structures throw (explicit field lists) or are skipped (wildcard).
- Soft deletes: with `embedding.soft_delete = false` (default) deleting a model removes its payload row alongside its embeddings; with `true` both are kept and restore leaves them untouched.

## 1.4.0 - 2026-05-21

- `max_length` config (`EMBEDDING_MAX_LENGTH`) — auto-truncate input before the embedding API call.

## 1.3.x - 2026-05-09 / 2026-05-10

- `embedding:status` shows the resolved AI provider + model in the configuration table (1.3.0); Storage section ordering (1.3.2).

## 1.2.x - 2026-05-09

- `embedding:status` command and `VectorStoreMetrics` storage-metrics contract (1.2.0).
- Cross-connection orphan / invalid-slot scans in `embedding:clean` (1.2.1, 1.2.2).

## 1.1.x - 2026-05-09

- Second-stage reranking via `laravel/ai` — `Reranker` service + `rerankWithScores()` Eloquent Collection macro (1.1.0).
- Embedding job dispatch deferred until the DB transaction commits (1.1.1).
- `rerank_score` set on single-item collections (1.1.2).
- `embedding:clean` streams IDs instead of buffering (1.1.3); `embeddingSlotMap()` cached per class (1.1.4).
- Soft-delete fixes: skip embed dispatch on soft-delete save, include trashed models in `PhpDriver` results (1.1.5, 1.1.6).
- Single-slot models reject non-default slot names (1.1.7); guard against empty AI responses in `similarToText` / `rankByRelevance` (1.1.8).

## 1.0.0 - 2026-05-09

- Initial release: `Embeddable` trait, `HasEmbeddings` contract, multi-slot embeddings (`$embeddable` map / repeatable `#[EmbedOn]`), queued `GenerateModelEmbedding` job, `VectorStore` contract with `JsonVectorStore` default, `SimilarityManager` with `php` driver, `similarTo` / `similarToText` / `mostSimilar` / `similarityTo` / `rankByRelevance`, model events, soft-delete handling, `embedding:generate` / `embedding:clear` / `embedding:clean` commands.
