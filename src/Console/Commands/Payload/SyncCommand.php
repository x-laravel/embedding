<?php

namespace XLaravel\Embedding\Console\Commands\Payload;

use Illuminate\Console\Command;
use Throwable;
use XLaravel\Embedding\Console\Commands\Concerns\ProcessesModelsInChunks;
use XLaravel\Embedding\Console\Commands\Concerns\ResolvesEmbeddableModels;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Jobs\SyncModelPayload;
use XLaravel\Embedding\Support\MissingPayloadResolver;

class SyncCommand extends Command
{
    use ProcessesModelsInChunks;
    use ResolvesEmbeddableModels;

    protected $signature = 'embedding:payload:sync
        {model? : The fully qualified model class name (auto-discovered when omitted)}
        {--limit= : Maximum number of records to process}
        {--chunk=100 : Number of records per chunk}
        {--sync : Upsert payload records inline instead of dispatching queued jobs}
        {--force : Re-sync payload records for all records, including existing ones}
        {--dry-run : Report counts per model without dispatching anything}';

    protected $description = 'Backfill missing payload (embeddables) records for HasEmbeddings models — no AI calls, no vectors (use --force to refresh existing rows)';

    public function handle(): int
    {
        $models = $this->resolveModels();

        if ($models === null) {
            return self::FAILURE;
        }

        if (empty($models)) {
            $this->warn('No models implementing HasEmbeddings were found.');

            return self::SUCCESS;
        }

        if (count($models) > 1 && ! $this->confirmModels($models)) {
            return self::SUCCESS;
        }

        $count = 0;
        $failures = [];

        foreach ($models as $modelClass) {
            if (count($models) > 1) {
                $this->newLine();
                $this->line("Model: <info>{$modelClass}</info>");
            }

            try {
                $count += $this->processModel($modelClass);
            } catch (Throwable $e) {
                $this->newLine();
                $this->error("  Failed: {$e->getMessage()}");

                if ($this->getOutput()->isVerbose()) {
                    $this->line("  at <comment>{$e->getFile()}:{$e->getLine()}</comment>");
                    $this->line($e->getTraceAsString());
                }

                $failures[$modelClass] = $e;
            }
        }

        $this->info($this->option('dry-run')
            ? "Dry-run: would sync payload for {$count} record(s)."
            : "Synced payload for {$count} record(s).");

        if (! empty($failures)) {
            $this->newLine();
            $this->warn('Some models failed:');
            foreach ($failures as $class => $exception) {
                $this->line("  - <comment>{$class}</comment>: {$exception->getMessage()}");
                if ($this->getOutput()->isVerbose()) {
                    $this->line("    at <comment>{$exception->getFile()}:{$exception->getLine()}</comment>");
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function processModel(string $modelClass): int
    {
        if (! (new $modelClass())->hasEmbeddingPayload()) {
            $this->warn("No embedding payload defined on [{$modelClass}].");

            return 0;
        }

        $resolver = MissingPayloadResolver::for($modelClass, (bool) $this->option('force'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $total = $resolver->count();
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->line("Payload — would process <comment>{$total}</comment> record(s)");

            return $total;
        }

        $this->line('Payload:');

        return $this->processIterableWithProgress(
            $resolver->lazyModels((int) $this->option('chunk')),
            $limit,
            $total,
            fn (HasEmbeddings $model) => $this->performTask($model),
        );
    }

    private function performTask(HasEmbeddings $model): void
    {
        $this->option('sync')
            ? $model->syncEmbeddingPayload()
            : dispatch(new SyncModelPayload($model));
    }
}
