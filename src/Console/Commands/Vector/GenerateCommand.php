<?php

namespace XLaravel\Embedding\Console\Commands\Vector;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use XLaravel\Embedding\Console\Commands\Concerns\ProcessesModelsInChunks;
use XLaravel\Embedding\Console\Commands\Concerns\ResolvesEmbeddableModels;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Support\SlotQueryPlanner;

class GenerateCommand extends Command
{
    use ProcessesModelsInChunks;
    use ResolvesEmbeddableModels;

    protected $signature = 'embedding:vector:generate
        {model? : The fully qualified model class name (auto-discovered when omitted)}
        {--slot= : Only generate for this specific slot (default: all slots)}
        {--limit= : Maximum number of records to process per slot}
        {--chunk=100 : Number of records per chunk}
        {--sync : Generate embeddings synchronously instead of dispatching queued jobs}
        {--force : Regenerate embeddings for all records, including existing ones}
        {--dry-run : Report counts per model and slot without dispatching anything}';

    protected $description = 'Generate missing vector embeddings for HasEmbeddings models (auto-discovers when no model is given, use --force to regenerate all)';

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
            ? "Dry-run: would generate embeddings for {$count} record(s)."
            : "Generated embeddings for {$count} record(s).");

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

    /**
     * @return array{0: Builder, 1: \Closure|null}
     */
    private function buildSlotPlan(string $modelClass, string $slot): array
    {
        return SlotQueryPlanner::plan($modelClass, $slot, (bool) $this->option('force'));
    }

    private function performTask(HasEmbeddings $model, string $slot): void
    {
        $this->option('sync')
            ? $model->embedSync($slot)
            : $model->embed($slot);
    }
}
