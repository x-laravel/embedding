<?php

namespace XLaravel\Embedding\Console\Commands\Payload;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsPayloadHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;

class CleanCommand extends Command
{
    use BuildsPayloadHealthQueries;
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:payload:clean
        {--chunk=1000 : Number of records per delete batch}
        {--force : Skip confirmation prompt}
        {--dry-run : Report findings without deleting}';

    protected $description = 'Clean stale payload (embeddables) records — model class missing, row deleted, or payload no longer defined. Vector embeddings are never touched — use embedding:vector:clean or embedding:clean for those.';

    public function handle(): int
    {
        $queries = $this->stalePayloadQueries();
        $total = $this->totalForQueries($queries);

        $this->line('Stale payload records: <comment>'.$total.'</comment>');

        if ($total === 0) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$total} payload record(s).");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$total} payload record(s)?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->deleteWithProgress($queries, $total);

        $this->info("Deleted {$total} payload record(s).");

        return self::SUCCESS;
    }
}
