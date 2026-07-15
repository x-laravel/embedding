<?php

namespace XLaravel\Embedding\Console\Commands\Payload;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;
use XLaravel\Embedding\Models\Embeddable;

class ClearCommand extends Command
{
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:payload:clear
        {model? : Fully qualified model class to clear (omit when using --all)}
        {--all : Clear payload records for every model (truncate path)}
        {--chunk=100 : Number of records per delete batch when not truncating}
        {--force : Skip confirmation prompt}
        {--dry-run : Report counts without deleting}';

    protected $description = 'Delete stored payload (embeddables) records for a specific model, or all of them with --all. Vector embeddings are never touched — use embedding:vector:clear or embedding:clear for those.';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $all = (bool) $this->option('all');

        if ($all && $modelClass !== null) {
            $this->error('The [model] argument cannot be combined with --all.');

            return self::FAILURE;
        }

        if (! $all && $modelClass === null) {
            $this->error('Provide a model class or use --all.');

            return self::FAILURE;
        }

        if ($modelClass !== null && ! class_exists($modelClass)) {
            $this->error("Class [{$modelClass}] does not exist.");

            return self::FAILURE;
        }

        $query = Embeddable::query();

        if ($modelClass !== null) {
            $query->where('embeddable_type', $modelClass);
        }

        $count = (clone $query)->count();
        $description = $all
            ? 'from the entire embeddables table'
            : "for [{$modelClass}]";

        if ($count === 0) {
            $this->info("No payload records to delete {$description}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$count} payload record(s) {$description}.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$count} payload record(s) {$description}?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if ($all) {
            $embeddable = new Embeddable();
            $embeddable->getConnection()->table($embeddable->getTable())->truncate();
        } else {
            $this->deleteWithProgress([$query], $count);
        }

        $this->info("Deleted {$count} payload record(s) {$description}.");

        return self::SUCCESS;
    }
}
