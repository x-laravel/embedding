<?php

namespace XLaravel\Embedding;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Reranking;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class Reranker
{
    public function rerank(
        EloquentCollection $candidates,
        string $query,
        int $take = 0,
        float $threshold = 0.0,
        ?string $field = null,
        string $slot = 'default',
    ): EloquentCollection {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // A single candidate has nothing to rerank against, so we skip the
        // provider call. We still set rerank_score so downstream code can
        // rely on the attribute being present regardless of result size.
        if ($candidates->count() === 1) {
            $candidates->first()->setAttribute('rerank_score', 1.0);

            return $candidates;
        }

        $candidatesByIndex = $candidates->values();

        $documents = $candidatesByIndex
            ->map(fn (Model $model) => $this->extractText($model, $field, $slot))
            ->all();

        $pending = Reranking::of($documents);

        if ($take > 0) {
            $pending->limit($take);
        }

        $response = $pending->rerank($query);

        $reranked = [];
        foreach ($response->results as $ranked) {
            $model = $candidatesByIndex[$ranked->index] ?? null;
            if ($model === null) {
                continue;
            }

            if ($threshold > 0.0 && $ranked->score < $threshold) {
                continue;
            }

            $model->setAttribute('rerank_score', $ranked->score);
            $reranked[] = $model;
        }

        return new EloquentCollection($reranked);
    }

    protected function extractText(Model $model, ?string $field, string $slot): string
    {
        if ($field !== null) {
            return (string) ($model->getAttribute($field) ?? '');
        }

        if (! $model instanceof HasEmbeddings) {
            return '';
        }

        $result = $model->toEmbeddingText();

        if (is_string($result)) {
            return $result;
        }

        return (string) ($result[$slot] ?? '');
    }
}
