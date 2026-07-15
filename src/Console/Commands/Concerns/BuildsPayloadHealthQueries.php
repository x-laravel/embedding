<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Models\Embeddable;

trait BuildsPayloadHealthQueries
{
    /**
     * @return array<int, Builder>
     */
    private function stalePayloadQueries(): array
    {
        $queries = [];

        $types = Embeddable::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            $query = $this->stalePayloadQueryForType((string) $type);

            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    private function stalePayloadQueryForType(string $type): ?Builder
    {
        if (! class_exists($type)) {
            return Embeddable::query()->where('embeddable_type', $type);
        }

        $instance = new $type();

        // A model that no longer declares a payload (attribute removed,
        // method deleted, or trait dropped) leaves all of its rows stale —
        // no search path can ever match them again.
        if (! method_exists($instance, 'hasEmbeddingPayload') || ! $instance->hasEmbeddingPayload()) {
            return Embeddable::query()->where('embeddable_type', $type);
        }

        $modelConnection = $instance->getConnection()->getName();
        $payloadConnection = (new Embeddable())->getConnection()->getName();

        if ($modelConnection === $payloadConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddablesTable = (new Embeddable())->getTable();

            // The subquery uses Query Builder so the SoftDeletes global scope
            // does not apply — soft-deleted rows still count as "exists" and
            // their preserved payload records are not misclassified as stale.
            return Embeddable::query()
                ->where('embeddable_type', $type)
                ->whereNotExists(function ($q) use ($modelTable, $modelKey, $embeddablesTable) {
                    $q->selectRaw('1')
                        ->from($modelTable)
                        ->whereColumn(
                            "{$modelTable}.{$modelKey}",
                            "{$embeddablesTable}.embeddable_id",
                        );
                });
        }

        // Cross-connection — pluck the (single-row-per-entity, so small)
        // embeddable_id set from the payload side, verify which still exist
        // on the model side, and turn the difference into a delete query.
        // Query Builder is used on the model side so the SoftDeletes scope
        // does not strip soft-deleted rows.
        $payloadIds = Embeddable::query()
            ->where('embeddable_type', $type)
            ->pluck('embeddable_id')
            ->all();

        if (empty($payloadIds)) {
            return null;
        }

        $existingIds = $instance->getConnection()
            ->table($instance->getTable())
            ->whereIn($instance->getKeyName(), $payloadIds)
            ->pluck($instance->getKeyName())
            ->all();

        $staleIds = array_values(array_diff($payloadIds, $existingIds));

        if (empty($staleIds)) {
            return null;
        }

        return Embeddable::query()
            ->where('embeddable_type', $type)
            ->whereIn('embeddable_id', $staleIds);
    }
}
