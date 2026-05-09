<?php

namespace XLaravel\Embedding\Events;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Models\Embedding;

class ModelEmbedded
{
    public function __construct(
        public readonly Model $model,
        public readonly Embedding $embedding,
        public readonly string $slot,
    ) {}
}
