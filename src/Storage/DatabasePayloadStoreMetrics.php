<?php

namespace XLaravel\Embedding\Storage;

use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Models\Embeddable;

class DatabasePayloadStoreMetrics implements PayloadStoreMetrics
{
    public function snapshot(): array
    {
        return [
            'rows' => Embeddable::query()->count(),
            'bytes' => null,
            'data_bytes' => null,
            'index_bytes' => null,
        ];
    }
}
