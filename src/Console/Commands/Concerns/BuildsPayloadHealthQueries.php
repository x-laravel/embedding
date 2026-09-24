<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Models\Embeddable;

trait BuildsPayloadHealthQueries
{
    use ReadsExistingModelRows;

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

            return Embeddable::query()
                ->where('embeddable_type', $type)
                ->whereNotExists(
                    $this->existingModelRows($instance)
                        ->selectRaw('1')
                        ->whereColumn("{$modelTable}.{$modelKey}", "{$embeddablesTable}.embeddable_id")
                );
        }

        // Cross-connection — pluck the (single-row-per-entity, so small)
        // embeddable_id set from the payload side, verify which still exist
        // on the model side, and turn the difference into a delete query.
        $payloadIds = Embeddable::query()
            ->where('embeddable_type', $type)
            ->pluck('embeddable_id')
            ->all();

        if (empty($payloadIds)) {
            return null;
        }

        $existingIds = $this->existingModelRows($instance)
            ->whereIn($instance->getQualifiedKeyName(), $payloadIds)
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
