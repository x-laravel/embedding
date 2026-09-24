<?php

namespace XLaravel\Embedding\Console\Commands\Vector;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsVectorHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;

class CleanCommand extends Command
{
    use BuildsVectorHealthQueries;
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:vector:clean
        {--orphans-only : Only delete orphan embeddings (model class missing or row deleted)}
        {--invalid-slots-only : Only delete embeddings whose slot is no longer defined on the model}
        {--chunk=1000 : Number of records per delete batch}
        {--force : Skip confirmation prompt}
        {--dry-run : Report findings without deleting}';

    protected $description = 'Clean orphan vector embeddings and records pointing at slots that no longer exist on their model. Payload records are never touched — use embedding:payload:clean or embedding:clean for those.';

    public function handle(): int
    {
        $orphansOnly = (bool) $this->option('orphans-only');
        $invalidSlotsOnly = (bool) $this->option('invalid-slots-only');

        if ($orphansOnly && $invalidSlotsOnly) {
            $this->error('--orphans-only and --invalid-slots-only cannot be combined.');

            return self::FAILURE;
        }

        $orphanQueries = $invalidSlotsOnly ? [] : $this->orphanQueries();
        $invalidQueries = $orphansOnly ? [] : $this->invalidSlotQueries();

        $orphanCount = $this->totalForQueries($orphanQueries);
        $invalidCount = $this->totalForQueries($invalidQueries);

        if (! $invalidSlotsOnly) {
            $this->line('Orphan records: <comment>'.$orphanCount.'</comment>');
        }

        if (! $orphansOnly) {
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
}
