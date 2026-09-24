<?php

namespace XLaravel\Embedding\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Persists the single payload record per entity used for filtered
 * similarity search. Write-side counterpart lives entirely outside the
 * vector path — implementations must not touch embedding records.
 */
interface PayloadStore
{
    /**
     * Insert or update the payload record for the given model.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsert(Model $model, array $payload): void;

    /**
     * Delete the payload record for the given model.
     */
    public function delete(Model $model): void;
}
