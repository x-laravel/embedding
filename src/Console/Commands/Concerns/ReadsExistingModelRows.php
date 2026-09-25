<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Support\KeyWindows;

trait ReadsExistingModelRows
{
    /**
     * Soft-deleted rows count as existing only while the model keeps their embeddings.
     */
    private function existingModelRows(Model $instance): Builder
    {
        return method_exists($instance, 'embeddingSubjectsQuery')
            ? $instance::embeddingSubjectsQuery()->toBase()
            : $instance->getConnection()->table($instance->getTable());
    }

    /**
     * The `embeddable_id`s of `$rows` whose model row is gone, for a model on another connection.
     *
     * @param  EloquentBuilder<Model>  $rows
     * @return list<int|string>
     */
    private function idsMissingFromModel(EloquentBuilder $rows, Model $instance): array
    {
        $missing = [];

        $windows = KeyWindows::missing(
            $rows,
            fn (array $ids) => KeyWindows::heldBy($this->existingModelRows($instance), $instance, $ids, $instance->getQualifiedKeyName()),
            key: 'embeddable_id',
        );

        foreach ($windows as $ids) {
            array_push($missing, ...$ids);
        }

        return $missing;
    }

    /**
     * @param  EloquentBuilder<Model>  $rows
     * @param  list<int|string>  $ids
     * @return array<int, EloquentBuilder<Model>>
     */
    private function rowsWithIds(EloquentBuilder $rows, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $binder = app(IdSetManager::class)->forQuery($rows);
        $limit = $binder->maxIdsPerQuery();

        return array_map(
            fn (array $chunk) => $binder->apply(clone $rows, 'embeddable_id', $chunk),
            array_chunk($ids, $limit > 0 ? $limit : count($ids)),
        );
    }
}
