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
            $queries = array_merge($queries, $this->stalePayloadQueriesForType((string) $type));
        }

        return $queries;
    }

    /**
     * @return array<int, Builder>
     */
    private function stalePayloadQueriesForType(string $type): array
    {
        if (! class_exists($type)) {
            return [Embeddable::query()->where('embeddable_type', $type)];
        }

        $instance = new $type();

        // A model that no longer declares a payload (attribute removed,
        // method deleted, or trait dropped) leaves all of its rows stale —
        // no search path can ever match them again.
        if (! method_exists($instance, 'hasEmbeddingPayload') || ! $instance->hasEmbeddingPayload()) {
            return [Embeddable::query()->where('embeddable_type', $type)];
        }

        $modelConnection = $instance->getConnection()->getName();
        $payloadConnection = (new Embeddable())->getConnection()->getName();

        if ($modelConnection === $payloadConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddablesTable = (new Embeddable())->getTable();

            return [
                Embeddable::query()
                    ->where('embeddable_type', $type)
                    ->whereNotExists(
                        $this->existingModelRows($instance)
                            ->selectRaw('1')
                            ->whereColumn("{$modelTable}.{$modelKey}", "{$embeddablesTable}.embeddable_id")
                    ),
            ];
        }

        $rows = Embeddable::query()->where('embeddable_type', $type);

        return $this->rowsWithIds($rows, $this->idsMissingFromModel($rows, $instance));
    }
}
