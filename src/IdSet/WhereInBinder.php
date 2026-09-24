<?php

namespace XLaravel\Embedding\IdSet;

use XLaravel\Embedding\Contracts\IdSetBinder;

/**
 * Portable fallback: one placeholder per ID. Works everywhere, but the cost of
 * binding grows faster than the list does, so the set is capped per query.
 */
class WhereInBinder implements IdSetBinder
{
    public function apply(mixed $query, string $column, array $ids, bool $negate = false): mixed
    {
        return $negate
            ? $query->whereNotIn($column, $ids)
            : $query->whereIn($column, $ids);
    }

    public function maxIdsPerQuery(): int
    {
        return 1000;
    }
}
