<?php

namespace XLaravel\Embedding\Console\Commands\Vector;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;
use XLaravel\Embedding\Models\Embedding;

class ClearCommand extends Command
{
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:vector:clear
        {model? : Fully qualified model class to clear (omit when using --all)}
        {--slot= : Only clear records for this specific slot}
        {--all : Clear embeddings for every model (truncate path when no other filter is set)}
        {--chunk=100 : Number of records per delete batch when not truncating}
        {--force : Skip confirmation prompt}
        {--dry-run : Report counts without deleting}';

    protected $description = 'Delete stored vector embeddings for a specific model, or all of them with --all. Payload records are never touched — use embedding:payload:clear or embedding:clear for those.';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $all = (bool) $this->option('all');
        $slot = $this->option('slot');

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

        $query = Embedding::query();

        if ($modelClass !== null) {
            $query->where('embeddable_type', $modelClass);
        }

        if ($slot !== null) {
            $query->where('slot', $slot);
        }

        $count = (clone $query)->count();
        $description = $this->describeTarget($modelClass, $slot, $all);

        if ($count === 0) {
            $this->info("No embeddings to delete {$description}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$count} embedding(s) {$description}.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$count} embedding(s) {$description}?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if ($all && $slot === null) {
            $embedding = new Embedding();
            $embedding->getConnection()->table($embedding->getTable())->truncate();
        } else {
            $this->deleteWithProgress([$query], $count);
        }

        $this->info("Deleted {$count} embedding(s) {$description}.");

        return self::SUCCESS;
    }

    private function describeTarget(?string $modelClass, ?string $slot, bool $all): string
    {
        if ($all && $slot === null) {
            return 'from the entire embeddings table';
        }

        if ($all && $slot !== null) {
            return "across all models for slot [{$slot}]";
        }

        if ($modelClass !== null && $slot !== null) {
            return "for [{$modelClass}] slot [{$slot}]";
        }

        return "for [{$modelClass}]";
    }
}
