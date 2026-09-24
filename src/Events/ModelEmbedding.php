<?php

namespace XLaravel\Embedding\Events;

use Illuminate\Database\Eloquent\Model;

class ModelEmbedding
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Model $model,
        public readonly string $slot,
    ) {}
}
