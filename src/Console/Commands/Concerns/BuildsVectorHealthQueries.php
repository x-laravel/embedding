<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Models\Embedding;

trait BuildsVectorHealthQueries
{
    /**
     * @return array<int, Builder>
     */
    private function orphanQueries(): array
    {
        $queries = [];

        $types = Embedding::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            $query = $this->orphanQueryForType((string) $type);

            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    private function orphanQueryForType(string $type): ?Builder
    {
        if (! class_exists($type)) {
            return Embedding::query()->where('embeddable_type', $type);
        }

        $instance = new $type();
        $modelConnection = $instance->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddingTable = (new Embedding())->getTable();

            // The subquery uses Query Builder so the SoftDeletes global scope
            // does not apply — soft-deleted rows still count as "exists" and
            // their preserved embeddings are not misclassified as orphans.
            return Embedding::query()
                ->where('embeddable_type', $type)
                ->whereNotExists(function ($q) use ($modelTable, $modelKey, $embeddingTable) {
                    $q->selectRaw('1')
                        ->from($modelTable)
                        ->whereColumn(
                            "{$modelTable}.{$modelKey}",
                            "{$embeddingTable}.embeddable_id",
                        );
                });
        }

        // Cross-connection — pluck the (usually small) distinct embeddable_id
        // set from the embedding side, verify which still exist on the model
        // side, and turn the difference into a delete query. Reverses the
        // naive direction (model → embedding) so we never ship a
        // multi-thousand IN clause to the embedding database for types whose
        // model table is large but barely embedded. Query Builder is used on
        // the model side so the SoftDeletes scope does not strip
        // soft-deleted rows.
        $distinctEmbeddedIds = Embedding::query()
            ->where('embeddable_type', $type)
            ->distinct()
            ->pluck('embeddable_id')
            ->all();

        if (empty($distinctEmbeddedIds)) {
            return null;
        }

        $existingIds = $instance->getConnection()
            ->table($instance->getTable())
            ->whereIn($instance->getKeyName(), $distinctEmbeddedIds)
            ->pluck($instance->getKeyName())
            ->all();

        $orphanIds = array_values(array_diff($distinctEmbeddedIds, $existingIds));

        if (empty($orphanIds)) {
            return null;
        }

        return Embedding::query()
            ->where('embeddable_type', $type)
            ->whereIn('embeddable_id', $orphanIds);
    }

    /**
     * @return array<int, Builder>
     */
    private function invalidSlotQueries(): array
    {
        $queries = [];

        $rows = Embedding::query()
            ->select('embeddable_type', 'slot')
            ->distinct()
            ->get();

        $slotsByType = [];
        foreach ($rows as $row) {
            $slotsByType[$row->embeddable_type][] = $row->slot;
        }

        foreach ($slotsByType as $type => $slots) {
            if (! class_exists($type) || ! is_a($type, HasEmbeddings::class, true)) {
                continue;
            }

            $validSlots = array_keys((new $type())->embeddingSlotMap());

            if (empty($validSlots)) {
                continue;
            }

            $invalidSlots = array_values(array_diff($slots, $validSlots));

            if (empty($invalidSlots)) {
                continue;
            }

            $instance = new $type();
            $modelConnection = $instance->getConnection()->getName();
            $embeddingConnection = (new Embedding())->getConnection()->getName();

            if ($modelConnection === $embeddingConnection) {
                $modelTable = $instance->getTable();
                $modelKey = $instance->getKeyName();
                $embeddingTable = (new Embedding())->getTable();

                // whereExists guarantees an invalid-slot record only counts
                // when the model row still exists. Records whose row is gone
                // are already covered by the orphan pass and must not be
                // counted twice.
                $queries[] = Embedding::query()
                    ->where('embeddable_type', $type)
                    ->whereIn('slot', $invalidSlots)
                    ->whereExists(function ($q) use ($modelTable, $modelKey, $embeddingTable) {
                        $q->selectRaw('1')
                            ->from($modelTable)
                            ->whereColumn(
                                "{$modelTable}.{$modelKey}",
                                "{$embeddingTable}.embeddable_id",
                            );
                    });

                continue;
            }

            // Cross-connection — pluck the candidate IDs straight from the
            // embedding side filtered by the invalid slot list, then verify
            // existence on the model side. Same direction reversal as
            // orphanQueryForType() to keep IN clauses small.
            $candidateIds = Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots)
                ->distinct()
                ->pluck('embeddable_id')
                ->all();

            if (empty($candidateIds)) {
                continue;
            }

            $existingIds = $instance->getConnection()
                ->table($instance->getTable())
                ->whereIn($instance->getKeyName(), $candidateIds)
                ->pluck($instance->getKeyName())
                ->all();

            if (empty($existingIds)) {
                continue;
            }

            $queries[] = Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots)
                ->whereIn('embeddable_id', $existingIds);
        }

        return $queries;
    }
}
