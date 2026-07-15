<?php

namespace XLaravel\Embedding\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Jobs\SyncModelPayload;
use XLaravel\Embedding\Models\Embeddable;
use XLaravel\Embedding\Models\Embedding;

class GenerateCommand extends Command
{
    protected $signature = 'embedding:generate
        {model? : The fully qualified model class name (auto-discovered when omitted)}
        {--slot= : Only generate for this specific slot (default: all slots)}
        {--limit= : Maximum number of records to process per slot}
        {--chunk=100 : Number of records per chunk}
        {--sync : Generate embeddings synchronously instead of dispatching queued jobs}
        {--force : Regenerate embeddings for all records, including existing ones}
        {--payload-only : Backfill payload (embeddables) records without touching vectors or the AI provider}
        {--dry-run : Report counts per model and slot without dispatching anything}';

    protected $description = 'Generate missing embeddings for HasEmbeddings models (auto-discovers when no model is given, use --force to regenerate all, --payload-only to backfill payload records)';

    public function handle(): int
    {
        if ($this->option('payload-only') && $this->option('slot') !== null) {
            $this->error('--payload-only and --slot cannot be combined.');

            return self::FAILURE;
        }

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

        if ($this->option('payload-only')) {
            $this->info($this->option('dry-run')
                ? "Dry-run: would sync payload for {$count} record(s)."
                : "Synced payload for {$count} record(s).");
        } else {
            $this->info($this->option('dry-run')
                ? "Dry-run: would generate embeddings for {$count} record(s)."
                : "Generated embeddings for {$count} record(s).");
        }

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

    /**
     * @return array<int, string>|null  null = explicit model failed validation
     */
    private function resolveModels(): ?array
    {
        $arg = $this->argument('model');

        if ($arg !== null) {
            return $this->validateModel($arg) ? [$arg] : null;
        }

        return $this->discoverModels();
    }

    /**
     * @return array<int, string>
     */
    private function discoverModels(): array
    {
        $path = is_dir(app_path('Models')) ? app_path('Models') : app_path();

        if (! is_dir($path)) {
            return [];
        }

        $namespace = $this->laravel->getNamespace();
        $basePath = realpath(app_path()) . DIRECTORY_SEPARATOR;

        $found = [];

        foreach ((new Finder())->in($path)->files()->name('*.php') as $file) {
            $class = $namespace . str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getRealPath(), $basePath)
            );

            try {
                if (! class_exists($class)) {
                    continue;
                }
            } catch (Throwable $e) {
                if ($this->getOutput()->isVerbose()) {
                    $this->line("  <comment>Skipped {$file->getRealPath()}</comment>: {$e->getMessage()}");
                }

                continue;
            }

            if (! is_a($class, HasEmbeddings::class, true)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * @param  array<int, string>  $models
     */
    private function confirmModels(array $models): bool
    {
        $this->line('Found <info>' . count($models) . '</info> models implementing HasEmbeddings:');
        foreach ($models as $model) {
            $this->line("  - <comment>{$model}</comment>");
        }

        return $this->confirm('Process all of them?', true);
    }

    private function processModel(string $modelClass): int
    {
        if ($this->option('payload-only')) {
            return $this->processPayload($modelClass);
        }

        $slots = $this->resolveSlots($modelClass);

        if (empty($slots)) {
            $this->warn("No embedding slots defined on [{$modelClass}].");

            return 0;
        }

        $count = 0;
        foreach ($slots as $slot) {
            $count += $this->processSlot($modelClass, $slot);
        }

        return $count;
    }

    private function validateModel(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            $this->error("Class [{$modelClass}] does not exist.");

            return false;
        }

        if (! is_a($modelClass, HasEmbeddings::class, true)) {
            $this->error("Class [{$modelClass}] does not implement HasEmbeddings.");

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function resolveSlots(string $modelClass): array
    {
        $slotFilter = $this->option('slot');

        return $slotFilter
            ? [$slotFilter]
            : array_keys((new $modelClass())->embeddingSlotMap());
    }

    private function processSlot(string $modelClass, string $slot): int
    {
        [$query, $filter] = $this->buildSlotPlan($modelClass, $slot);
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
            $this->line("Slot: <info>{$slot}</info> — would process <comment>{$total}</comment> record(s){$suffix}");

            return $total;
        }

        $this->line("Slot: <info>{$slot}</info>");

        return $this->processWithProgress(
            $query,
            $filter,
            $limit,
            $total,
            fn (HasEmbeddings $model) => $this->performTask($model, $slot),
        );
    }

    private function processPayload(string $modelClass): int
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
            fn (HasEmbeddings $model) => $this->performPayloadTask($model),
        );
    }

    private function processWithProgress(Builder $query, ?Closure $filter, ?int $limit, int $total, Closure $task): int
    {
        $processed = 0;
        $chunk = (int) $this->option('chunk');

        $this->withProgressBar($total, function ($bar) use ($query, $filter, $chunk, $limit, $task, &$processed) {
            $query->chunk($chunk, function ($models) use ($filter, $limit, $task, &$processed, $bar) {
                if ($filter !== null) {
                    $models = $filter($models);
                }

                foreach ($models as $model) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $task($model);
                    $processed++;
                    $bar->advance();
                }
            });
        });

        $this->newLine();

        return $processed;
    }

    /**
     * @return array{0: Builder, 1: \Closure|null}
     */
    private function buildSlotPlan(string $modelClass, string $slot): array
    {
        if ($this->option('force')) {
            return [$modelClass::query(), null];
        }

        $modelConnection = (new $modelClass())->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            return [
                $modelClass::whereDoesntHave('embeddings', fn ($q) => $q->where('slot', $slot)),
                null,
            ];
        }

        $filter = function ($models) use ($modelClass, $slot) {
            if ($models->isEmpty()) {
                return $models;
            }

            $existingIds = Embedding::query()
                ->where('embeddable_type', $modelClass)
                ->where('slot', $slot)
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

    private function performTask(HasEmbeddings $model, string $slot): void
    {
        $this->option('sync')
            ? $model->embedSync($slot)
            : $model->embed($slot);
    }

    private function performPayloadTask(HasEmbeddings $model): void
    {
        $this->option('sync')
            ? $model->syncEmbeddingPayload()
            : dispatch(new SyncModelPayload($model));
    }
}
