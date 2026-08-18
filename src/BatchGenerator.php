<?php

namespace XLaravel\Embedding;

use Closure;
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
     *
     * $finally, when given, is registered on the batch before it is first
     * dispatched — a Batch (unlike a PendingBatch) cannot have callbacks
     * attached after the fact, so this must be threaded in up front rather
     * than added by the caller once dispatch() returns.
     */
    public function dispatch(
        string $modelClass,
        ?string $slot = null,
        bool $force = false,
        int $chunk = 100,
        ?Closure $finally = null,
    ): ?Batch {
        $slots = $slot !== null ? [$slot] : array_keys((new $modelClass())->embeddingSlotMap());

        $batch = null;

        foreach ($slots as $currentSlot) {
            [$query, $filter] = SlotQueryPlanner::plan($modelClass, $currentSlot, $force);

            $query->chunk($chunk, function ($models) use (&$batch, $filter, $currentSlot, $modelClass, $finally) {
                if ($filter !== null) {
                    $models = $filter($models);
                }

                if ($models->isEmpty()) {
                    return;
                }

                $jobs = $models->map(fn ($model) => new GenerateModelEmbedding($model, $currentSlot))->all();

                if ($batch === null) {
                    $pending = Bus::batch([])->name('embedding-generate:'.class_basename($modelClass));

                    if ($finally !== null) {
                        $pending->finally($finally);
                    }

                    $batch = $pending->dispatch();
                }

                $batch->add($jobs);
            });
        }

        return $batch;
    }
}
