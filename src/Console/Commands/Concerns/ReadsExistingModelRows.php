<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

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
}
