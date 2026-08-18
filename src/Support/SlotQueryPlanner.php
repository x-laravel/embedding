<?php

namespace XLaravel\Embedding\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Models\Embedding;

/**
 * Builds the "records missing this slot's embedding" query plan, shared
 * between the console generate command, BatchGenerator, and
 * missingEmbeddingCount(). Same-connection setups filter "not yet embedded"
 * at the database level (whereDoesntHave); cross-connection setups fall
 * back to a pluck + reject filter applied per chunk. Either way, the
 * returned filter also drops records whose *resolved* text (after
 * toEmbeddingText()) is blank — eligibleForEmbedding() only sees the raw
 * source columns, so it cannot catch content that normalizes down to
 * nothing (e.g. a description of "-----").
 */
class SlotQueryPlanner
{
    /**
     * @return array{0: Builder, 1: Closure}
     */
    public static function plan(string $modelClass, string $slot, bool $force = false): array
    {
        $textFilter = self::nonBlankTextFilter($slot);

        if ($force) {
            return [$modelClass::query()->eligibleForEmbedding($slot), $textFilter];
        }

        $modelConnection = (new $modelClass())->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            return [
                $modelClass::whereDoesntHave('embeddings', fn ($q) => $q->where('slot', $slot))
                    ->eligibleForEmbedding($slot),
                $textFilter,
            ];
        }

        $notYetEmbeddedFilter = function ($models) use ($modelClass, $slot) {
            if ($models->isEmpty()) {
                return $models;
            }

            $existingIds = Embedding::query()
                ->where('embeddable_type', $modelClass)
                ->where('slot', $slot)
                ->whereIn('embeddable_id', $models->modelKeys())
                ->pluck('embeddable_id')
                ->all();

            if (empty($existingIds)) {
                return $models;
            }

            $existing = array_flip(array_map('strval', $existingIds));

            return $models->reject(fn ($model) => isset($existing[(string) $model->getKey()]));
        };

        return [
            $modelClass::query()->eligibleForEmbedding($slot),
            fn ($models) => $notYetEmbeddedFilter($textFilter($models)),
        ];
    }

    /**
     * Chunk through plan()'s query, applying its filter, and collect the
     * keys of every record that is genuinely missing embeddable content
     * for the slot. Used where the actual ID set is needed (table filters)
     * rather than just a count.
     *
     * @return array<int, int|string>
     */
    public static function missingIds(string $modelClass, string $slot, bool $force = false, int $chunkSize = 500): array
    {
        [$query, $filter] = self::plan($modelClass, $slot, $force);

        $ids = [];

        $query->chunk($chunkSize, function ($models) use ($filter, &$ids) {
            foreach ($filter($models) as $model) {
                $ids[] = $model->getKey();
            }
        });

        return $ids;
    }

    private static function nonBlankTextFilter(string $slot): Closure
    {
        return fn ($models) => $models->filter(
            fn ($model) => trim($model->toEmbeddingText($slot)) !== ''
        );
    }
}
