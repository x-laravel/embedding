<?php

namespace XLaravel\Embedding\Storage;

use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Models\Embedding;

class JsonVectorStoreMetrics implements VectorStoreMetrics
{
    public function snapshot(): array
    {
        return [
            'rows' => Embedding::query()->count(),
            'bytes' => null,
            'data_bytes' => null,
            'index_bytes' => null,
        ];
    }
}
