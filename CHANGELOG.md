# Changelog

All notable changes to `x-laravel/embedding` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The package's major version follows `laravel/ai`.

## 1.0.2 - 2026-09-24

### Added

- `embeddingSubjectsQuery()` and `keepsEmbeddingsOfTrashed()` on the `Embeddable` trait — the records that should hold embeddings, including soft-deleted rows while `keepEmbeddingOnSoftDelete()` is true.

### Fixed

- With embeddings kept on soft delete (`embedding.soft_delete` or `$keepEmbeddingOnSoftDelete`), soft-deleted records now count toward `missingEmbeddingCount()`, `embeddedCount()` and the `embedding:vector:status` / `embedding:payload:status` coverage, and `embedding:vector:generate` / `embedding:payload:sync` fill them in. Previously they were left out, so a record soft-deleted before the setting was enabled stayed without an embedding and was never reported.
- Without kept embeddings, `embedding:vector:clean` and `embedding:payload:clean` treat rows still left for soft-deleted records as orphans.

## 1.0.1 - 2026-09-24

### Fixed

- `similarToText()` and `rankByRelevance()` return an empty collection when the provider returns no embedding while `ai.caching.embeddings.individually` is enabled, instead of letting `EmbeddingsCountMismatchException` escape.

## 1.0.0 - 2026-09-24

Initial release. Requires PHP ^8.3, Laravel ^12.0 | ^13.0 and `laravel/ai` ^1.0.

### Added

- `Embeddable` trait and `HasEmbeddings` contract — embeddings are generated automatically on save through a queued `GenerateModelEmbedding` job per slot.
- Multi-slot embeddings: a nested `$embeddable` map or the repeatable `#[EmbedOn]` attribute defines which fields trigger each slot; only the slots whose fields changed are re-embedded. `toEmbeddingText(string $slot = 'default')` builds the requested slot's text only.
- Generation API: `embed()`, `embedSync()`, `hasEmbedding()`, `embedding()` / `embeddings()` relations, `withoutEmbedding()` / `disableEmbedding()` / `enableEmbedding()`.
- `GenerateModelEmbedding` is `ShouldBeUnique` (model + id + slot) and `Batchable`; dispatch is deferred until the surrounding transaction commits. Lock expiry via `embedding.queue.unique_for`.
- Blank-text guard: `scopeEligibleForEmbedding()` excludes records whose slot source fields are empty, and `EmptyEmbeddingTextException` stops a request whose resolved text is blank before it reaches the provider.
- `BatchGenerator` — dispatches missing-embedding generation as a trackable `Bus::batch()`, with an optional `finally` callback.
- Coverage counters `embeddedCount()` and `missingEmbeddingCount()`, backed by `MissingSlotResolver`, which narrows to candidates before resolving text and walks keys in fixed windows (`Support\KeyWindows`) for cross-connection setups.
- Payload filtering: `#[EmbedPayload]` (explicit field list or `'*'` wildcard with `except`) and `toEmbeddingPayload()` publish scalar attributes into the `embeddables` table, written by the `SyncModelPayload` job on its own queue. `similarTo()`, `similarToText()` and `mostSimilar()` accept `filter:` (equality, IN, AND) evaluated inside the similarity query.
- Similarity search: `similarTo()`, `similarToText()`, `mostSimilar()`, `similarityTo()`, `rankByRelevance()`, each setting `similarity_score`. Driver-based through `SimilarityManager` with a built-in `php` driver and an `auto` selector for the DB driver packages (MySQL, MariaDB, PostgreSQL, Oracle, SQL Server, Qdrant).
- Second-stage reranking through `laravel/ai`: `Reranker` service and `rerankWithScores()` collection macro, setting `rerank_score`.
- Model events `onEmbedding` / `onEmbedded`, observer methods, and `ModelEmbedding` / `ModelEmbedded` Laravel events.
- Soft-delete handling with `embedding.soft_delete` and per-model `$keepEmbeddingOnSoftDelete`.
- `VectorStore` / `PayloadStore` contracts with database defaults, and `VectorStoreMetrics` / `PayloadStoreMetrics` for driver-supplied storage figures.
- Artisan commands: `embedding:vector:generate|clear|clean|status`, `embedding:payload:sync|clear|clean|status`, `embedding:clear`, `embedding:clean` and `embedding:storage`.
- `max_length` config to truncate input before the embedding API call.
- Publishable migrations (`embedding-migrations`) and config (`embedding-config`).
