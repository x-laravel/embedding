<?php

namespace XLaravel\Embedding\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

        // restore() calls save() internally; restored() handles embedding for restores
        if ($this->usesSoftDelete($model)
            && $model->wasChanged($model->getDeletedAtColumn())
            && is_null($model->getAttribute($model->getDeletedAtColumn()))) {
            return;
        }

        foreach ($model->slotsToEmbed(array_keys($model->getChanges())) as $slot) {
            $model->embed($slot);
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
        }
    }

    /**
     * Handle the model "force deleted" event.
     */
    public function forceDeleted(Model $model): void
    {
        $model->embeddings()->delete();
    }

    /**
     * Determine if the model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
