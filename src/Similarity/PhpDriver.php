<?php

namespace XLaravel\Embedding\Similarity;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Contracts\SearchRequest;
use XLaravel\Embedding\Contracts\SimilarityDriver;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;

class PhpDriver implements SimilarityDriver
{
    /**
     * Search for models similar to the request's query vector using PHP-side cosine similarity.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function search(Model $prototype, SearchRequest $request): Collection
    {
        $morphClass = $prototype->getMorphClass();
        $embeddingClass = config('embedding.model');

        $query = app($embeddingClass)->where('embeddable_type', $morphClass)->where('slot', $request->slot);

        if ($request->ids !== null) {
            $query->whereIn('embeddable_id', $request->ids);
        }

        if (! empty($request->filter)) {
            $this->applyPayloadFilter($query, $request->filter);
        }

        $mapped = $query->get()->map(fn ($e) => [
            'id' => $e->embeddable_id,
            'score' => Metrics::cosine($request->vector, $e->vector),
        ]);

        if ($request->threshold > 0.0) {
            $mapped = $mapped->filter(fn ($r) => $r['score'] >= $request->threshold);
        }

        $results = $mapped->sortByDesc('score')->take($request->limit);

        $matchedIds = $results->pluck('id')->all();
        $scores = $results->pluck('score', 'id')->all();

        // When the model uses SoftDeletes and embedding.soft_delete=true (or
        // a per-model keepEmbeddingOnSoftDelete=true), trashed rows still
        // own embedding records and can score against the query — but the
        // default global scope hides them from findMany(), silently
        // dropping otherwise-matching results. Include trashed rows so the
        // caller decides what to do with them.
        $modelQuery = in_array(SoftDeletes::class, class_uses_recursive($prototype), true)
            ? $prototype::query()->withTrashed()
            : $prototype::query();

        return $modelQuery->findMany($matchedIds)
            ->each(fn ($m) => $m->setAttribute('similarity_score', $scores[$m->getKey()] ?? 0.0))
            ->sortByDesc(fn ($m) => $m->getAttribute('similarity_score'))
            ->values();
    }

    /**
     * Constrain the embeddings query to rows whose payload record matches
     * the filter: scalar values compare as equality, arrays as IN, entries
     * are ANDed. Runs as whereExists against the embeddables table on the
     * same connection — the model database is never touched. Records
     * without a payload row never match a filtered search.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\XLaravel\Embedding\Models\Embedding>  $query
     * @param  array<string, mixed>  $filter
     */
    protected function applyPayloadFilter($query, array $filter): void
    {
        $embeddingsTable = $query->getModel()->getTable();
        $embeddablesTable = (new EmbeddableRecord())->getTable();

        $query->whereExists(
            EmbeddableRecord::query()
                ->whereColumn("{$embeddablesTable}.embeddable_type", "{$embeddingsTable}.embeddable_type")
                ->whereColumn("{$embeddablesTable}.embeddable_id", "{$embeddingsTable}.embeddable_id")
                ->where(function ($payloadQuery) use ($filter) {
                    foreach ($filter as $key => $value) {
                        is_array($value)
                            ? $payloadQuery->whereIn("payload->{$key}", $value)
                            : $payloadQuery->where("payload->{$key}", $value);
                    }
                })
        );
    }
}
