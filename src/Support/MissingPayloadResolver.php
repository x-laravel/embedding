<?php

namespace XLaravel\Embedding\Support;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use XLaravel\Embedding\Models\Embeddable as EmbeddablePayload;

/**
 * Resolves the records that have no payload row yet.
 *
 * The vector side's counterpart, minus the text: a payload is entity-level,
 * so there is no slot and nothing to resolve per record. On a shared
 * connection the anti-join answers it; across connections the two key sets
 * meet in windows.
 */
class MissingPayloadResolver
{
    private function __construct(
        private readonly string $modelClass,
        private readonly bool $force = false,
    ) {}

    public static function for(string $modelClass, bool $force = false): self
    {
        return new self($modelClass, $force);
    }

    /**
     * @return LazyCollection<int, Model>
     */
    public function lazyModels(int $chunk = 500): LazyCollection
    {
        return LazyCollection::make(function () use ($chunk): Generator {
            if ($this->usesKeyDiff()) {
                foreach ($this->missingKeyWindows() as $ids) {
                    foreach (array_chunk($ids, $chunk) as $slice) {
                        foreach ($this->modelClass::embeddingSubjectsQuery()->whereKey($slice)->get() as $model) {
                            yield $model;
                        }
                    }
                }

                return;
            }

            foreach ($this->baseQuery()->lazyById($chunk, $this->prototype()->getKeyName()) as $model) {
                yield $model;
            }
        });
    }

    public function count(): int
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
     * @return Generator<int, array<int, int|string>>
     */
    private function missingKeyWindows(): Generator
    {
        return KeyWindows::missing(
            $this->modelClass::embeddingSubjectsQuery(),
            fn (array $ids) => KeyWindows::heldBy($this->payloadQuery(), $this->prototype(), $ids),
        );
    }

    /**
     * @return Builder<Model>
     */
    private function payloadQuery(): Builder
    {
        return EmbeddablePayload::query()
            ->where('embeddable_type', $this->prototype()->getMorphClass());
    }

    /**
     * @return Builder<Model>
     */
    private function baseQuery(): Builder
    {
        if ($this->force) {
            return $this->modelClass::embeddingSubjectsQuery();
        }

        $prototype = $this->prototype();
        $morphClass = $prototype->getMorphClass();
        $modelTable = $prototype->getTable();
        $modelKey = $prototype->getKeyName();
        $payloadTable = (new EmbeddablePayload)->getTable();

        return $this->modelClass::embeddingSubjectsQuery()->whereNotExists(
            function ($query) use ($payloadTable, $morphClass, $modelTable, $modelKey) {
                $query->selectRaw('1')
                    ->from($payloadTable)
                    ->where("{$payloadTable}.embeddable_type", $morphClass)
                    ->whereColumn("{$payloadTable}.embeddable_id", "{$modelTable}.{$modelKey}");
            }
        );
    }

    private function usesKeyDiff(): bool
    {
        return ! $this->force
            && $this->prototype()->getConnection()->getName()
                !== (new EmbeddablePayload)->getConnection()->getName();
    }

    private function prototype(): Model
    {
        return new $this->modelClass;
    }
}
