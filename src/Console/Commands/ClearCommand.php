<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use XLaravel\Embedding\Models\Embedding;

class ClearCommand extends Command
{
    protected $signature = 'embedding:clear
        {model? : Fully qualified model class to clear (omit when using --all)}
        {--slot= : Only clear records for this specific slot}
        {--all : Clear embeddings for every model (truncate path when no other filter is set)}
        {--chunk=100 : Number of records per delete batch when not truncating}
        {--force : Skip confirmation prompt}
        {--dry-run : Report counts without deleting}';

    protected $description = 'Delete stored embeddings for a specific model, or all of them with --all.';

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
            $this->deleteWithProgress($query, $count);
        }

        $this->info("Deleted {$count} embedding(s) {$description}.");

        return self::SUCCESS;
    }

    private function deleteWithProgress(\Illuminate\Database\Eloquent\Builder $query, int $total): void
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $key = (new Embedding())->getKeyName();

        $this->withProgressBar($total, function ($bar) use ($query, $chunk, $key) {
            $query->select([$key])->chunkById($chunk, function ($rows) use ($bar, $key) {
                Embedding::query()->whereIn($key, $rows->pluck($key)->all())->delete();
                $bar->advance($rows->count());
            }, $key);
        });

        $this->newLine();
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
