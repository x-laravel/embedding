<?php

namespace XLaravel\Embedding\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Contracts\PayloadStore;
use XLaravel\Embedding\Jobs\SyncModelPayload;

class EmbeddingObserver
{
    /**
     * The model classes for which embedding syncing is disabled.
     *
     * @var array<class-string, true>
     */
    protected static array $syncingDisabledFor = [];

    /**
     * Disable embedding syncing for the given model class.
     */
    public static function disableSyncingFor(string $class): void
    {
        static::$syncingDisabledFor[$class] = true;
    }

    /**
     * Enable embedding syncing for the given model class.
     */
    public static function enableSyncingFor(string $class): void
    {
        unset(static::$syncingDisabledFor[$class]);
    }

    /**
     * Determine if syncing is disabled for the given model class.
     */
    public static function syncingDisabledFor(object|string $class): bool
    {
        $class = is_object($class) ? get_class($class) : $class;

        return isset(static::$syncingDisabledFor[$class]);
    }

    /**
     * Handle the model "saved" event.
     */
    public function saved(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        // Both soft-delete and restore call save() internally. Skip embed
        // dispatch in either case: deleted() / restored() handle those
        // transitions on their own. Without this guard, wildcard
        // ($embeddable = ['*']) models would re-embed during a soft-delete
        // and race the deleted() handler that removes the rows.
        if ($this->usesSoftDelete($model)
            && $model->wasChanged($model->getDeletedAtColumn())) {
            return;
        }

        $changedKeys = array_keys($model->getChanges());

        foreach ($model->slotsToEmbed($changedKeys) as $slot) {
            $model->embed($slot);
        }

        // Payload dispatch is independent of slots. SyncModelPayload
        // is the single writer of payload records — slot jobs never write
        // them, so no per-slot race over the shared row can occur. The
        // wasRecentlyCreated guard mirrors slotsToEmbed(): on insert
        // getChanges() is empty; on a later update of the same instance
        // changedKeys is non-empty and the field-based check runs instead.
        $payloadDirty = $model->payloadFieldsChanged($changedKeys)
            || ($model->wasRecentlyCreated && empty($changedKeys) && $model->hasEmbeddingPayload());

        if ($payloadDirty) {
            dispatch(new SyncModelPayload($model));
        }
    }

    /**
     * Handle the model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        if ($this->usesSoftDelete($model) && $model->keepEmbeddingOnSoftDelete()) {
            return;
        }

        $model->embeddings()->delete();
        app(PayloadStore::class)->delete($model);
    }

    /**
     * Handle the model "restored" event.
     */
    public function restored(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        if (! $model->keepEmbeddingOnSoftDelete()) {
            foreach (array_keys($model->embeddingSlotMap()) as $slot) {
                $model->embed($slot);
            }

            // The payload row was removed on soft delete — rebuild it.
            // Under keepEmbeddingOnSoftDelete the row was never deleted.
            if ($model->hasEmbeddingPayload()) {
                dispatch(new SyncModelPayload($model));
            }
        }
    }

    /**
     * Handle the model "force deleted" event.
     */
    public function forceDeleted(Model $model): void
    {
        $model->embeddings()->delete();
        app(PayloadStore::class)->delete($model);
    }

    /**
     * Determine if the model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
