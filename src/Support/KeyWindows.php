<?php

namespace XLaravel\Embedding\Support;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use XLaravel\Embedding\IdSetManager;

/**
 * Walks a model's keys in fixed windows, asking another connection once per
 * window which of those keys it already holds.
 *
 * Keys travel through the query builder rather than as models: a window is
 * compared, not worked on, and hydrating a model per key costs more than the
 * comparison it feeds. Only the keys the other side does not hold come back,
 * so the caller hydrates a candidate set instead of a table.
 */
class KeyWindows
{
    public const SIZE = 5000;

    /**
     * A `$key` other than the model's own key may repeat across rows, so it is
     * read distinct.
     *
     * @param  Builder<Model>  $source
     * @param  Closure(array<int, int|string>): array<string, true>  $held
     * @return Generator<int, array<int, int|string>>
     */
    public static function missing(Builder $source, Closure $held, int $window = self::SIZE, ?string $key = null): Generator
    {
        $model = $source->getModel();
        $key ??= $model->getKeyName();
        $column = $model->getTable().'.'.$key;
        $after = null;

        while (true) {
            $query = (clone $source)->toBase()
                ->select($column)
                ->distinct($key !== $model->getKeyName())
                ->orderBy($column)
                ->limit($window);

            if ($after !== null) {
                $query->where($column, '>', $after);
            }

            $ids = $query->pluck($key)->all();

            if ($ids === []) {
                return;
            }

            $after = end($ids);
            $existing = $held($ids);

            yield array_values(array_filter(
                $ids,
                fn ($id) => ! isset($existing[(string) $id]),
            ));
        }
    }

    /**
     * Which of the given keys a morph-keyed table on another connection holds.
     * Integer keys arrive ordered, so the window is a range that side can
     * answer with one indexed read; anything else travels as an explicit set,
     * in whatever portions the driver can bind.
     *
     * @param  Builder<Model>|QueryBuilder  $query
     * @param  array<int, int|string>  $ids
     * @return array<string, true>
     */
    public static function heldBy(Builder|QueryBuilder $query, Model $prototype, array $ids, string $column = 'embeddable_id'): array
    {
        if ($ids === []) {
            return [];
        }

        if ($prototype->getKeyType() === 'int') {
            $found = (clone $query)
                ->whereBetween($column, [reset($ids), end($ids)])
                ->pluck($column)
                ->all();

            return array_fill_keys(array_map('strval', $found), true);
        }

        $binder = app(IdSetManager::class)->forQuery($query);
        $perQuery = $binder->maxIdsPerQuery() ?: count($ids);
        $existing = [];

        foreach (array_chunk($ids, $perQuery) as $slice) {
            $sliceQuery = clone $query;
            $binder->apply($sliceQuery, $column, $slice);

            foreach ($sliceQuery->pluck($column) as $id) {
                $existing[(string) $id] = true;
            }
        }

        return $existing;
    }
}
