<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\DeletesRecordsInChunks;
use XLaravel\Embedding\Models\Embeddable;
use XLaravel\Embedding\Models\Embedding;

class ClearCommand extends Command
{
    use DeletesRecordsInChunks;

    protected $signature = 'embedding:clear
        {model? : Fully qualified model class to clear (omit when using --all)}
        {--all : Clear embeddings and payload records for every model (truncate path)}
        {--chunk=100 : Number of records per delete batch when not truncating}
        {--force : Skip confirmation prompt}
        {--dry-run : Report counts without deleting}';

    protected $description = 'Delete stored vector embeddings AND payload records for a specific model, or all of them with --all. For a single table use embedding:vector:clear / embedding:payload:clear.';

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

        $query = Embedding::query();
        $payloadQuery = Embeddable::query();

        if ($modelClass !== null) {
            $query->where('embeddable_type', $modelClass);
            $payloadQuery->where('embeddable_type', $modelClass);
        }

        $count = (clone $query)->count();
        $payloadCount = (clone $payloadQuery)->count();
        $description = $this->describeTarget($modelClass, $all, $payloadCount);
        $subject = $this->describeSubject($count, $payloadCount);

        if ($count === 0 && $payloadCount === 0) {
            $this->info("No embeddings to delete {$description}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: would delete {$subject} {$description}.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$subject} {$description}?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if ($all) {
            $embedding = new Embedding();
            $embedding->getConnection()->table($embedding->getTable())->truncate();

            $embeddable = new Embeddable();
            $embeddable->getConnection()->table($embeddable->getTable())->truncate();
        } else {
            $this->deleteWithProgress(
                array_filter([
                    $count > 0 ? $query : null,
                    $payloadCount > 0 ? $payloadQuery : null,
                ]),
                $count + $payloadCount,
            );
        }

        $this->info("Deleted {$subject} {$description}.");

        return self::SUCCESS;
    }

    private function describeSubject(int $count, int $payloadCount): string
    {
        $subject = "{$count} embedding(s)";

        if ($payloadCount > 0) {
            $subject .= " and {$payloadCount} payload record(s)";
        }

        return $subject;
    }

    private function describeTarget(?string $modelClass, bool $all, int $payloadCount): string
    {
        if ($all) {
            return $payloadCount > 0
                ? 'from the entire embeddings and embeddables tables'
                : 'from the entire embeddings table';
        }

        return "for [{$modelClass}]";
    }
}
