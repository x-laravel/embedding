<?php

namespace XLaravel\Embedding\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Models\Embedding;

/**
 * Builds the "records missing this slot's embedding" query plan, shared
 * between the console generate command and BatchGenerator. Same-connection
 * setups filter at the database level (whereDoesntHave); cross-connection
 * setups fall back to a pluck + reject filter applied per chunk.
 */
class SlotQueryPlanner
{
    /**
     * @return array{0: Builder, 1: Closure|null}
     */
    public static function plan(string $modelClass, string $slot, bool $force = false): array
    {
        if ($force) {
            return [$modelClass::query()->eligibleForEmbedding($slot), null];
        }

        $modelConnection = (new $modelClass())->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            return [
                $modelClass::whereDoesntHave('embeddings', fn ($q) => $q->where('slot', $slot))
                    ->eligibleForEmbedding($slot),
                null,
            ];
        }

        $filter = function ($models) use ($modelClass, $slot) {
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

        return [$modelClass::query()->eligibleForEmbedding($slot), $filter];
    }
}
