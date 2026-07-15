<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Models\Embeddable;
use XLaravel\Embedding\Models\Embedding;

class CleanCommand extends Command
{
    protected $signature = 'embedding:clean
        {--orphans-only : Only delete orphan embeddings (model class missing or row deleted)}
        {--invalid-slots-only : Only delete embeddings whose slot is no longer defined on the model}
        {--payload-only : Only delete stale payload (embeddables) records}
        {--chunk=1000 : Number of records per delete batch}
        {--force : Skip confirmation prompt}
        {--dry-run : Report findings without deleting}';

    protected $description = 'Clean orphan embeddings, records pointing at slots that no longer exist on their model, and stale payload records.';

    public function handle(): int
    {
        $orphansOnly = (bool) $this->option('orphans-only');
        $invalidSlotsOnly = (bool) $this->option('invalid-slots-only');
        $payloadOnly = (bool) $this->option('payload-only');

        $exclusive = array_keys(array_filter([
            '--orphans-only' => $orphansOnly,
            '--invalid-slots-only' => $invalidSlotsOnly,
            '--payload-only' => $payloadOnly,
        ]));

        if (count($exclusive) > 1) {
            $this->error(implode(' and ', $exclusive).' cannot be combined.');

            return self::FAILURE;
        }

        $cleanOrphans = ! $invalidSlotsOnly && ! $payloadOnly;
        $cleanInvalidSlots = ! $orphansOnly && ! $payloadOnly;
        $cleanPayload = ! $orphansOnly && ! $invalidSlotsOnly;

        $orphanQueries = $cleanOrphans ? $this->orphanQueries() : [];
        $invalidQueries = $cleanInvalidSlots ? $this->invalidSlotQueries() : [];
        $payloadQueries = $cleanPayload ? $this->payloadQueries() : [];

        $orphanCount = $this->totalForQueries($orphanQueries);
        $invalidCount = $this->totalForQueries($invalidQueries);
        $payloadCount = $this->totalForQueries($payloadQueries);

        if ($cleanOrphans) {
            $this->line('Orphan records: <comment>'.$orphanCount.'</comment>');
        }

        if ($cleanInvalidSlots) {
            $this->line('Invalid slot records: <comment>'.$invalidCount.'</comment>');
        }

        if ($cleanPayload) {
            $this->line('Stale payload records: <comment>'.$payloadCount.'</comment>');
        }

        $total = $orphanCount + $invalidCount + $payloadCount;

        if ($total === 0) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$total} record(s).");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$total} record(s)?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->deleteWithProgress(array_merge($orphanQueries, $invalidQueries, $payloadQueries), $total);

        $this->info("Deleted {$total} record(s).");

        return self::SUCCESS;
    }

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

    /**
     * @return array<int, Builder>
     */
    private function payloadQueries(): array
    {
        $queries = [];

        $types = Embeddable::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            $query = $this->payloadQueryForType((string) $type);

            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    private function payloadQueryForType(string $type): ?Builder
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

    /**
     * @param  array<int, Builder>  $queries
     */
    private function totalForQueries(array $queries): int
    {
        $sum = 0;

        foreach ($queries as $query) {
            $sum += (clone $query)->count();
        }

        return $sum;
    }

    /**
     * @param  array<int, Builder>  $queries
     */
    private function deleteWithProgress(array $queries, int $total): void
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->withProgressBar($total, function ($bar) use ($queries, $chunkSize) {
            foreach ($queries as $query) {
                // Queries target either the embeddings or the embeddables
                // model — derive the key/model from the query itself.
                $model = $query->getModel();
                $key = $model->getKeyName();

                $query->chunkById($chunkSize, function ($rows) use ($bar, $model, $key) {
                    $model->newQuery()
                        ->whereIn($key, $rows->modelKeys())
                        ->delete();
                    $bar->advance($rows->count());
                }, $key);
            }
        });

        $this->newLine();
    }
}