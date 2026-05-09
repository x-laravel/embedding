<?php

namespace XLaravel\Embedding\Storage;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Models\Embedding;

class JsonVectorStore implements VectorStore
{
    public function store(Model $model, array $vector, string $slot): Embedding
    {
        $embeddingClass = config('embedding.model');

        return $embeddingClass::updateOrCreate(
            [
                'embeddable_type' => $model->getMorphClass(),
                'embeddable_id' => $model->getKey(),
                'slot' => $slot,
            ],
            ['vector' => $vector]
        );
    }
}
