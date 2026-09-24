<?php

namespace XLaravel\Embedding\Storage;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\PayloadStore;
use XLaravel\Embedding\Models\Embeddable;

class DatabasePayloadStore implements PayloadStore
{
    public function upsert(Model $model, array $payload): void
    {
        // upsert() bypasses Eloquent casts, so the payload is encoded by hand.
        Embeddable::upsert(
            [[
                'embeddable_type' => $model->getMorphClass(),
                'embeddable_id' => $model->getKey(),
                'payload' => json_encode($payload),
            ]],
            ['embeddable_type', 'embeddable_id'],
            ['payload'],
        );
    }

    public function delete(Model $model): void
    {
        Embeddable::query()
            ->where('embeddable_type', $model->getMorphClass())
            ->where('embeddable_id', $model->getKey())
            ->delete();
    }
}
