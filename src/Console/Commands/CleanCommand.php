<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Models\Embedding;

class CleanCommand extends Command
{
    protected $signature = 'embedding:clean
        {--orphans-only : Only delete orphan records (model class missing or row deleted)}
        {--invalid-slots-only : Only delete records whose slot is no longer defined on the model}
        {--chunk=1000 : Number of records per delete batch}
        {--force : Skip confirmation prompt}
        {--dry-run : Report findings without deleting}';

    protected $description = 'Clean orphan embeddings and records pointing at slots that no longer exist on their model.';

    public function handle(): int
    {
        $orphansOnly = (bool) $this->option('orphans-only');
        $invalidSlotsOnly = (bool) $this->option('invalid-slots-only');

        if ($orphansOnly && $invalidSlotsOnly) {
            $this->error('--orphans-only and --invalid-slots-only cannot be combined.');

            return self::FAILURE;
        }

        $cleanOrphans = ! $invalidSlotsOnly;
        $cleanInvalidSlots = ! $orphansOnly;

        $orphanQueries = $cleanOrphans ? $this->orphanQueries() : [];
        $invalidQueries = $cleanInvalidSlots ? $this->invalidSlotQueries() : [];

        $orphanCount = $this->totalForQueries($orphanQueries);
        $invalidCount = $this->totalForQueries($invalidQueries);

        if ($cleanOrphans) {
            $this->line('Orphan records: <comment>'.$orphanCount.'</comment>');
        }

        if ($cleanInvalidSlots) {
            $this->line('Invalid slot records: <comment>'.$invalidCount.'</comment>');
        }

        $total = $orphanCount + $invalidCount;

        if ($total === 0) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$total} embedding(s).");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$total} embedding(s)?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->deleteWithProgress(array_merge($orphanQueries, $invalidQueries), $total);

        $this->info("Deleted {$total} embedding(s).");

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
            $queries[] = $this->orphanQueryForType((string) $type);
        }

        return $queries;
    }

    private function orphanQueryForType(string $type): Builder
    {
        if (! class_exists($type)) {
            return Embedding::query()->where('embeddable_type', $type);
        }

        $instance = new $type();
        $modelTable = $instance->getTable();
        $modelKey = $instance->getKeyName();
        $embeddingTable = (new Embedding())->getTable();

        // The subquery uses Query Builder so the SoftDeletes global scope does
        // not apply — soft-deleted rows still count as "exists" and their
        // preserved embeddings are not misclassified as orphans.
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
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddingTable = (new Embedding())->getTable();

            // whereExists guarantees an invalid-slot record only counts when
            // the model row still exists. Records whose row is gone are
            // already covered by the orphan pass and must not be counted twice.
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
        }

        return $queries;
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
        $key = (new Embedding())->getKeyName();

        $this->withProgressBar($total, function ($bar) use ($queries, $chunkSize, $key) {
            foreach ($queries as $query) {
                $query->chunkById($chunkSize, function ($embeddings) use ($bar, $key) {
                    Embedding::query()
                        ->whereIn($key, $embeddings->modelKeys())
                        ->delete();
                    $bar->advance($embeddings->count());
                }, $key);
            }
        });

        $this->newLine();
    }
}