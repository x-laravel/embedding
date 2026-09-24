<?php

namespace XLaravel\Embedding\Support;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Resolves the records that still need an embedding for one slot.
 *
 * Narrow to candidates first, then resolve each candidate's text. Resolving
 * text means hydrating the record, so the narrowing belongs in the database
 * wherever one can see both sides — an anti-join when the model and the
 * embeddings share a connection. Across connections the two key sets meet in
 * PHP instead, and even then they meet in windows, never whole.
 */
class MissingSlotResolver
{
    private function __construct(
        private readonly string $modelClass,
        private readonly string $slot,
        private readonly bool $force = false,
    ) {}

    public static function for(string $modelClass, string $slot, bool $force = false): self
    {
        return new self($modelClass, $slot, $force);
    }

    /**
     * Candidates whose resolved text is non-blank — what generation would act on.
     *
     * @return LazyCollection<int, Model>
     */
    public function lazyModels(int $chunk = 500): LazyCollection
    {
        return LazyCollection::make(function () use ($chunk): Generator {
            foreach ($this->candidateChunks($chunk) as $models) {
                foreach ($this->withResolvableText($models) as $model) {
                    yield $model;
                }
            }
        });
    }

    /**
     * @return LazyCollection<int, int|string>
     */
    public function lazyIds(int $chunk = 500): LazyCollection
    {
        return $this->lazyModels($chunk)->map(fn (Model $model) => $model->getKey());
    }

    /**
     * @return array<int, int|string>
     */
    public function ids(int $chunk = 500): array
    {
        return $this->lazyIds($chunk)->all();
    }

    public function count(int $chunk = 500): int
    {
        return $this->lazyModels($chunk)->count();
    }

    /**
     * Candidates before their text is resolved — no hydration, so a progress
     * total can be had without paying for the answer twice.
     */
    public function baseCount(): int
    {
        if ($this->usesKeyDiff()) {
            $total = 0;

            foreach ($this->missingKeyWindows() as $ids) {
                $total += count($ids);
            }

            return $total;
        }

        return $this->baseQuery()->count();
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return Collection<int, Model>
     */
    private function withResolvableText(Collection $models): Collection
    {
        return $models->filter(
            fn (Model $model) => trim($model->toEmbeddingText($this->slot)) !== ''
        );
    }

    /**
     * @return Generator<int, Collection<int, Model>>
     */
    private function candidateChunks(int $chunk): Generator
    {
        if ($this->usesKeyDiff()) {
            foreach ($this->missingKeyWindows() as $ids) {
                foreach (array_chunk($ids, $chunk) as $slice) {
                    yield $this->modelClass::query()->whereKey($slice)->get();
                }
            }

            return;
        }

        $key = $this->prototype()->getKeyName();

        foreach ($this->baseQuery()->lazyById($chunk, $key)->chunk($chunk) as $models) {
            yield new Collection($models->all());
        }
    }

    /**
     * @return Generator<int, array<int, int|string>>
     */
    private function missingKeyWindows(): Generator
    {
        return KeyWindows::missing(
            $this->eligibleQuery(),
            fn (array $ids) => KeyWindows::heldBy($this->embeddingQuery(), $this->prototype(), $ids),
        );
    }

    /**
     * @return Builder<Model>
     */
    private function embeddingQuery(): Builder
    {
        $embeddingModel = config('embedding.model');

        return $embeddingModel::query()
            ->where('embeddable_type', $this->prototype()->getMorphClass())
            ->where('slot', $this->slot);
    }

    /**
     * @return Builder<Model>
     */
    private function baseQuery(): Builder
    {
        if ($this->force) {
            return $this->eligibleQuery();
        }

        return $this->eligibleQuery()
            ->whereDoesntHave('embeddings', fn ($query) => $query->where('slot', $this->slot));
    }

    /**
     * @return Builder<Model>
     */
    private function eligibleQuery(): Builder
    {
        return $this->modelClass::query()->eligibleForEmbedding($this->slot);
    }

    private function usesKeyDiff(): bool
    {
        return ! $this->force && ! $this->sharesConnectionWithEmbeddings();
    }

    private function sharesConnectionWithEmbeddings(): bool
    {
        $embeddingModel = config('embedding.model');

        return $this->prototype()->getConnection()->getName()
            === (new $embeddingModel)->getConnection()->getName();
    }

    private function prototype(): Model
    {
        return new $this->modelClass;
    }
}
