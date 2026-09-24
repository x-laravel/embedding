<?php

namespace XLaravel\Embedding\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface SimilarityDriver
{
    /**
     * Search for models similar to the request's query vector.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $prototype  An instance of the target model class
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function search(Model $prototype, SearchRequest $request): Collection;
}
