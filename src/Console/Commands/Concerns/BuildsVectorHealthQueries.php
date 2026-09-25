<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Models\Embedding;

trait BuildsVectorHealthQueries
{
    use ReadsExistingModelRows;

    /**
     * @return array<int, Builder>
     */
    private function orphanQueries(): array
    {
        $queries = [];
        $invalidSlots = $this->invalidSlotsByType();

        $types = Embedding::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            $queries = array_merge(
                $queries,
                $this->orphanQueriesForType((string) $type, $invalidSlots[(string) $type] ?? []),
            );
        }

        return $queries;
    }

    /**
     * Rows whose slot no longer exists are reported by the invalid-slot pass,
     * so they are excluded here — otherwise a record that is both orphaned and
     * on a dropped slot would be counted twice.
     *
     * @param  list<string>  $invalidSlots
     * @return array<int, Builder>
     */
    private function orphanQueriesForType(string $type, array $invalidSlots = []): array
    {
        if (! class_exists($type)) {
            return [Embedding::query()->where('embeddable_type', $type)];
        }

        $instance = new $type();
        $modelConnection = $instance->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddingTable = (new Embedding())->getTable();

            return [
                Embedding::query()
                    ->where('embeddable_type', $type)
                    ->when($invalidSlots !== [], fn ($query) => $query->whereNotIn('slot', $invalidSlots))
                    ->whereNotExists(
                        $this->existingModelRows($instance)
                            ->selectRaw('1')
                            ->whereColumn("{$modelTable}.{$modelKey}", "{$embeddingTable}.embeddable_id")
                    ),
            ];
        }

        $rows = Embedding::query()
            ->where('embeddable_type', $type)
            ->when($invalidSlots !== [], fn ($query) => $query->whereNotIn('slot', $invalidSlots));

        return $this->rowsWithIds($rows, $this->idsMissingFromModel($rows, $instance));
    }

    /**
     * A dropped slot is decided by class metadata alone, so the query needs no
     * model-side lookup: whether the row still exists only decides which pass
     * reports it, and the orphan pass already skips these slots. This keeps the
     * query at two bindings even when the type has a million embeddings — the
     * model and embedding tables may live on different connections, where a
     * lookup would mean shipping every ID across.
     *
     * @return array<int, Builder>
     */
    private function invalidSlotQueries(): array
    {
        $queries = [];

        foreach ($this->invalidSlotsByType() as $type => $invalidSlots) {
            $queries[] = Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots);
        }

        return $queries;
    }

    /**
     * Stored slots that the model class no longer declares, per type.
     *
     * @return array<string, list<string>>
     */
    private function invalidSlotsByType(): array
    {
        $rows = Embedding::query()
            ->select('embeddable_type', 'slot')
            ->distinct()
            ->get();

        $slotsByType = [];
        foreach ($rows as $row) {
            $slotsByType[$row->embeddable_type][] = $row->slot;
        }

        $invalid = [];

        foreach ($slotsByType as $type => $slots) {
            if (! class_exists($type) || ! is_a($type, HasEmbeddings::class, true)) {
                continue;
            }

            $validSlots = array_keys((new $type())->embeddingSlotMap());

            if (empty($validSlots)) {
                continue;
            }

            $invalidSlots = array_values(array_diff($slots, $validSlots));

            if ($invalidSlots !== []) {
                $invalid[$type] = $invalidSlots;
            }
        }

        return $invalid;
    }
}
