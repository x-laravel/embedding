<?php

namespace XLaravel\Embedding\Contracts;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Models\Embedding;

interface VectorStore
{
    public function store(Model $model, array $vector, string $slot): Embedding;
}
