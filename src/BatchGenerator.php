<?php

namespace XLaravel\Embedding;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Support\SlotQueryPlanner;

/**
 * Dispatches missing-embedding generation as a trackable Bus::batch(),
 * instead of the fire-and-forget dispatch used by GenerateModelEmbedding
 * on its own. Lets callers (e.g. a UI button) observe real completion via
 * the returned Batch, rather than guessing when the work is done.
 */
class BatchGenerator
{
    /**
     * Dispatch a batch covering every record missing an embedding for the
     * given slot(s). Returns null (no batch created) when nothing is missing.
     */
    public function dispatch(string $modelClass, ?string $slot = null, bool $force = false, int $chunk = 100): ?Batch
    {
        $slots = $slot !== null ? [$slot] : array_keys((new $modelClass())->embeddingSlotMap());

        $batch = null;

        foreach ($slots as $currentSlot) {
            [$query, $filter] = SlotQueryPlanner::plan($modelClass, $currentSlot, $force);

            $query->chunk($chunk, function ($models) use (&$batch, $filter, $currentSlot, $modelClass) {
                if ($filter !== null) {
                    $models = $filter($models);
                }

                if ($models->isEmpty()) {
                    return;
                }

                $jobs = $models->map(fn ($model) => new GenerateModelEmbedding($model, $currentSlot))->all();

                $batch ??= Bus::batch([])->name('embedding-generate:'.class_basename($modelClass))->dispatch();
                $batch->add($jobs);
            });
        }

        return $batch;
    }
}
