<?php

namespace XLaravel\Embedding\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface SimilarityDriver
{
    /**
     * Search for models similar to the given query vector.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $prototype  An instance of the target model class
     * @param  array<int, float>  $queryVector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  array<int, mixed>|null  $ids  Restrict the search to these primary keys
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function search(Model $prototype, array $queryVector, int $limit, float $threshold = 0.0, ?array $ids = null, string $slot = 'default'): Collection;
}
