<?php

namespace XLaravel\Embedding\Similarity;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\SimilarityDriver;

class PhpDriver implements SimilarityDriver
{
    /**
     * Search for models similar to the given query vector using PHP-side cosine similarity.
     *
     * @param  array<int, float>  $queryVector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  array<int, mixed>|null  $ids  Restrict the search to these primary keys
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function search(Model $prototype, array $queryVector, int $limit, float $threshold = 0.0, ?array $ids = null, string $slot = 'default'): Collection
    {
        $morphClass = $prototype->getMorphClass();
        $embeddingClass = config('embedding.model');

        $query = app($embeddingClass)->where('embeddable_type', $morphClass)->where('slot', $slot);

        if ($ids !== null) {
            $query->whereIn('embeddable_id', $ids);
        }

        $mapped = $query->get()->map(fn ($e) => [
            'id' => $e->embeddable_id,
            'score' => Metrics::cosine($queryVector, $e->vector),
        ]);

        if ($threshold > 0.0) {
            $mapped = $mapped->filter(fn ($r) => $r['score'] >= $threshold);
        }

        $results = $mapped->sortByDesc('score')->take($limit);

        $matchedIds = $results->pluck('id')->all();
        $scores = $results->pluck('score', 'id')->all();

        return $prototype::findMany($matchedIds)
            ->each(fn ($m) => $m->setAttribute('similarity_score', $scores[$m->getKey()] ?? 0.0))
            ->sortByDesc(fn ($m) => $m->getAttribute('similarity_score'))
            ->values();
    }
}
