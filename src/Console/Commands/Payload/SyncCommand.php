<?php

namespace XLaravel\Embedding\Console\Commands\Payload;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use XLaravel\Embedding\Console\Commands\Concerns\ProcessesModelsInChunks;
use XLaravel\Embedding\Console\Commands\Concerns\ResolvesEmbeddableModels;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Jobs\SyncModelPayload;
use XLaravel\Embedding\Models\Embeddable;

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

        [$query, $filter] = $this->buildPayloadPlan($modelClass);
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $total = (clone $query)->count();
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            return 0;
        }

        if ($this->option('dry-run')) {
            $suffix = $filter !== null ? ' <comment>(approximate, cross-connection)</comment>' : '';
            $this->line("Payload — would process <comment>{$total}</comment> record(s){$suffix}");

            return $total;
        }

        $this->line('Payload:');

        return $this->processWithProgress(
            $query,
            $filter,
            $limit,
            $total,
            fn (HasEmbeddings $model) => $this->performTask($model),
        );
    }

    /**
     * @return array{0: Builder, 1: \Closure|null}
     */
    private function buildPayloadPlan(string $modelClass): array
    {
        if ($this->option('force')) {
            return [$modelClass::query(), null];
        }

        $instance = new $modelClass();
        $morphClass = $instance->getMorphClass();
        $modelConnection = $instance->getConnection()->getName();
        $payloadConnection = (new Embeddable())->getConnection()->getName();

        if ($modelConnection === $payloadConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddablesTable = (new Embeddable())->getTable();

            return [
                $modelClass::query()->whereNotExists(function ($q) use ($embeddablesTable, $morphClass, $modelTable, $modelKey) {
                    $q->selectRaw('1')
                        ->from($embeddablesTable)
                        ->where("{$embeddablesTable}.embeddable_type", $morphClass)
                        ->whereColumn(
                            "{$embeddablesTable}.embeddable_id",
                            "{$modelTable}.{$modelKey}",
                        );
                }),
                null,
            ];
        }

        $filter = function ($models) use ($morphClass) {
            if ($models->isEmpty()) {
                return $models;
            }

            $existingIds = Embeddable::query()
                ->where('embeddable_type', $morphClass)
                ->whereIn('embeddable_id', $models->modelKeys())
                ->pluck('embeddable_id')
                ->all();

            if (empty($existingIds)) {
                return $models;
            }

            $existing = array_flip(array_map('strval', $existingIds));

            return $models->reject(fn ($model) => isset($existing[(string) $model->getKey()]));
        };

        return [$modelClass::query(), $filter];
    }

    private function performTask(HasEmbeddings $model): void
    {
        $this->option('sync')
            ? $model->syncEmbeddingPayload()
            : dispatch(new SyncModelPayload($model));
    }
}
