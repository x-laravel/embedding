<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Models\Embedding;

class CleanCommand extends Command
{
    protected $signature = 'embedding:clean
        {--orphans-only : Only delete orphan records (model class missing or row deleted)}
        {--invalid-slots-only : Only delete records whose slot is no longer defined on the model}
        {--chunk=100 : Number of records per delete batch}
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

        $orphanIds = $cleanOrphans ? $this->findOrphanIds() : [];
        $invalidSlotIds = $cleanInvalidSlots ? $this->findInvalidSlotIds($orphanIds) : [];

        $allIds = array_values(array_unique(array_merge($orphanIds, $invalidSlotIds)));

        if ($cleanOrphans) {
            $this->line('Orphan records: <comment>'.count($orphanIds).'</comment>');
        }

        if ($cleanInvalidSlots) {
            $this->line('Invalid slot records: <comment>'.count($invalidSlotIds).'</comment>');
        }

        if (empty($allIds)) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        $total = count($allIds);

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$total} embedding(s).");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$total} embedding(s)?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->deleteByIdsWithProgress($allIds);

        $this->info("Deleted {$total} embedding(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function deleteByIdsWithProgress(array $ids): void
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $key = (new Embedding())->getKeyName();
        $batches = array_chunk($ids, $chunkSize);

        $this->withProgressBar(count($ids), function ($bar) use ($batches, $key) {
            foreach ($batches as $batch) {
                Embedding::query()->whereIn($key, $batch)->delete();
                $bar->advance(count($batch));
            }
        });

        $this->newLine();
    }

    /**
     * @return array<int, int|string>
     */
    private function findOrphanIds(): array
    {
        $orphans = [];

        $types = Embedding::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            if (! class_exists($type)) {
                $ids = Embedding::query()
                    ->where('embeddable_type', $type)
                    ->pluck((new Embedding())->getKeyName())
                    ->all();

                $orphans = array_merge($orphans, $ids);

                continue;
            }

            $instance = new $type();
            $modelQuery = $type::query();

            // Soft-deleted rows still exist in the table — their embeddings are not orphans.
            // Without withTrashed(), the global scope hides trashed rows and their preserved
            // embeddings (kept via embedding.soft_delete=true or $keepEmbeddingOnSoftDelete)
            // would be misclassified as orphans.
            if (in_array(SoftDeletes::class, class_uses_recursive($type), true)) {
                $modelQuery->withTrashed();
            }

            $existingKeys = $modelQuery->pluck($instance->getKeyName())->all();

            $missing = Embedding::query()
                ->where('embeddable_type', $type)
                ->when(
                    ! empty($existingKeys),
                    fn ($q) => $q->whereNotIn('embeddable_id', $existingKeys),
                )
                ->pluck((new Embedding())->getKeyName())
                ->all();

            $orphans = array_merge($orphans, $missing);
        }

        return $orphans;
    }

    /**
     * @param  array<int, int|string>  $excludeIds  IDs already classified (e.g. as orphans) to skip.
     * @return array<int, int|string>
     */
    private function findInvalidSlotIds(array $excludeIds = []): array
    {
        $invalid = [];

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

            $ids = Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots)
                ->when(
                    ! empty($excludeIds),
                    fn ($q) => $q->whereNotIn((new Embedding())->getKeyName(), $excludeIds),
                )
                ->pluck((new Embedding())->getKeyName())
                ->all();

            $invalid = array_merge($invalid, $ids);
        }

        return $invalid;
    }
}
