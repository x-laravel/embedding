<?php

namespace XLaravel\Embedding\Contracts;

interface VectorStoreMetrics
{
    /**
     * Return a driver-specific snapshot of the embedding storage.
     *
     * `rows` is always an `int` — every database can serve a row count via
     * Eloquent. The byte fields are best-effort: drivers that cannot reach
     * the underlying metadata (e.g. MySQL `information_schema` without DBA
     * privileges) must return `null` and let `embedding:status` render
     * "n/a". Implementations must not throw to signal "not supported";
     * reserve exceptions for genuine I/O failures.
     *
     * `rows` may be approximate when a driver reports it via fast metadata
     * tables (e.g. MySQL `information_schema.tables.table_rows`); for an
     * exact count, callers can always fall back to `Embedding::count()`.
     *
     * @return array{
     *     rows: int,
     *     bytes: int|null,
     *     data_bytes: int|null,
     *     index_bytes: int|null,
     * }
     */
    public function snapshot(): array;
}
