<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsPayloadHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsVectorHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;

class CleanCommand extends Command
{
    use BuildsPayloadHealthQueries;
    use BuildsVectorHealthQueries;
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:clean
        {--chunk=1000 : Number of records per delete batch}
        {--force : Skip confirmation prompt}
        {--dry-run : Report findings without deleting}';

    protected $description = 'Clean orphan embeddings, records pointing at slots that no longer exist on their model, and stale payload records. For a single table use embedding:vector:clean / embedding:payload:clean.';

    public function handle(): int
    {
        $orphanQueries = $this->orphanQueries();
        $invalidQueries = $this->invalidSlotQueries();
        $payloadQueries = $this->stalePayloadQueries();

        $orphanCount = $this->totalForQueries($orphanQueries);
        $invalidCount = $this->totalForQueries($invalidQueries);
        $payloadCount = $this->totalForQueries($payloadQueries);

        $this->line('Orphan records: <comment>'.$orphanCount.'</comment>');
        $this->line('Invalid slot records: <comment>'.$invalidCount.'</comment>');
        $this->line('Stale payload records: <comment>'.$payloadCount.'</comment>');

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
}
