<?php

namespace XLaravel\Embedding\Contracts;

/**
 * Constrains a query by a set of IDs that came from another database.
 *
 * Embeddings and their models may live on separate connections, where no join
 * can span the two: one side is read into PHP and carried to the other. How
 * that set travels is a property of the receiving database — a driver can bind
 * it as one structured value, while the fallback binds one placeholder per ID
 * and therefore has a practical ceiling.
 */
interface IdSetBinder
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     * @param  array<int, int|string>  $ids
     */
    public function apply(mixed $query, string $column, array $ids, bool $negate = false): mixed;

    /**
     * How many IDs one query may carry; 0 means no practical limit. Callers
     * that build their own queries chunk the set by this number.
     */
    public function maxIdsPerQuery(): int;
}
