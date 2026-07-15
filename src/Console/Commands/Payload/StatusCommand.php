<?php

namespace XLaravel\Embedding\Console\Commands\Payload;

use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsPayloadHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\ResolvesEmbeddableModels;
use XLaravel\Embedding\Console\Commands\Concerns\SumsQueryCounts;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Models\Embeddable;
use XLaravel\Embedding\Models\Embedding;

class StatusCommand extends Command
{
    use BuildsPayloadHealthQueries;
    use ResolvesEmbeddableModels;
    use SumsQueryCounts;

    protected $signature = 'embedding:payload:status
        {model? : Restrict the report to a single HasEmbeddings model class}
        {--json : Emit a single JSON object suitable for CI / monitoring}';

    protected $description = 'Show a read-only health report for the payload (embeddables) table (configuration, coverage, stale records, storage size). Vector health lives in embedding:vector:status.';

    public function handle(): int
    {
        $models = $this->resolveModels();

        if ($models === null) {
            return self::FAILURE;
        }

        $configuration = $this->collectConfiguration();
        $coverage = $this->collectCoverage($models);
        $health = $this->collectHealth($models);
        $storage = $this->collectStorage();

        if ($this->option('json')) {
            $this->line(json_encode([
                'configuration' => $configuration,
                'models' => $coverage,
                'health' => $health,
                'storage' => $storage,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $this->renderConfiguration($configuration);
        $this->renderCoverage($coverage);
        $this->renderHealth($health);
        $this->renderStorage($storage);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     db_connection: string|null,
     *     db_table: string|null,
     *     queue_connection: string|null,
     *     queue_name: string|null,
     * }
     */
    private function collectConfiguration(): array
    {
        return [
            'db_connection' => config('embedding.database.connection'),
            'db_table' => config('embedding.database.embeddables_table'),
            'queue_connection' => config('embedding.queue.connection'),
            'queue_name' => config('embedding.queue.sync_payload'),
        ];
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function collectCoverage(array $models): array
    {
        $rows = [];

        foreach ($models as $modelClass) {
            if (! (new $modelClass())->hasEmbeddingPayload()) {
                $rows[] = [
                    'model' => $modelClass,
                    'records' => null,
                    'with_payload' => null,
                    'coverage' => null,
                    'note' => 'no payload defined',
                ];

                continue;
            }

            $total = $modelClass::query()->count();
            $withPayload = $this->countRecordsWithPayload($modelClass);
            $coverage = $total > 0 ? round($withPayload / $total * 100, 1) : null;

            $rows[] = [
                'model' => $modelClass,
                'records' => $total,
                'with_payload' => $withPayload,
                'coverage' => $coverage,
                'note' => null,
            ];
        }

        return $rows;
    }

    private function countRecordsWithPayload(string $modelClass): int
    {
        $instance = new $modelClass();
        $morphClass = $instance->getMorphClass();
        $modelConnection = $instance->getConnection()->getName();
        $payloadConnection = (new Embeddable())->getConnection()->getName();

        if ($modelConnection === $payloadConnection) {
            $modelTable = $instance->getTable();
            $modelKey = $instance->getKeyName();
            $embeddablesTable = (new Embeddable())->getTable();

            return $modelClass::query()
                ->whereExists(function ($q) use ($embeddablesTable, $morphClass, $modelTable, $modelKey) {
                    $q->selectRaw('1')
                        ->from($embeddablesTable)
                        ->where("{$embeddablesTable}.embeddable_type", $morphClass)
                        ->whereColumn(
                            "{$embeddablesTable}.embeddable_id",
                            "{$modelTable}.{$modelKey}",
                        );
                })
                ->count();
        }

        // Cross-connection — the payload table holds at most one row per
        // entity, so the ID list stays small. Pluck it from the payload
        // side and verify existence on the model side.
        $payloadIds = Embeddable::query()
            ->where('embeddable_type', $morphClass)
            ->pluck('embeddable_id')
            ->all();

        if (empty($payloadIds)) {
            return 0;
        }

        return $modelClass::query()
            ->whereIn($instance->getKeyName(), $payloadIds)
            ->count();
    }

    /**
     * @param  array<int, string>  $models
     * @return array{
     *     models_with_payload: int,
     *     payload_rows: int,
     *     stale_payload_records: int,
     *     embedded_entities_missing_payload: int,
     * }
     */
    private function collectHealth(array $models): array
    {
        $withPayload = array_values(array_filter(
            $models,
            fn (string $modelClass) => (new $modelClass())->hasEmbeddingPayload(),
        ));

        $missing = 0;
        foreach ($withPayload as $modelClass) {
            $missing += $this->countEmbeddedEntitiesMissingPayload($modelClass);
        }

        return [
            'models_with_payload' => count($withPayload),
            'payload_rows' => Embeddable::query()->count(),
            'stale_payload_records' => $this->totalForQueries($this->stalePayloadQueries()),
            'embedded_entities_missing_payload' => $missing,
        ];
    }

    private function countEmbeddedEntitiesMissingPayload(string $modelClass): int
    {
        $morphClass = (new $modelClass())->getMorphClass();
        $embeddingTable = (new Embedding())->getTable();
        $embeddablesTable = (new Embeddable())->getTable();

        // Both tables always live on the embedding connection, so the
        // whereNotExists never crosses databases regardless of where the
        // model itself lives.
        return Embedding::query()
            ->where('embeddable_type', $morphClass)
            ->whereNotExists(function ($q) use ($embeddablesTable, $embeddingTable) {
                $q->selectRaw('1')
                    ->from($embeddablesTable)
                    ->whereColumn(
                        "{$embeddablesTable}.embeddable_type",
                        "{$embeddingTable}.embeddable_type",
                    )
                    ->whereColumn(
                        "{$embeddablesTable}.embeddable_id",
                        "{$embeddingTable}.embeddable_id",
                    );
            })
            ->distinct()
            ->count('embeddable_id');
    }

    /**
     * @return array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}
     */
    private function collectStorage(): array
    {
        $default = ['rows' => null, 'bytes' => null, 'data_bytes' => null, 'index_bytes' => null];

        if (! $this->laravel->bound(PayloadStoreMetrics::class)) {
            return $default;
        }

        try {
            $snapshot = $this->laravel->make(PayloadStoreMetrics::class)->snapshot();
        } catch (Throwable $e) {
            if ($this->getOutput()->isVerbose()) {
                $this->line("  <comment>storage metrics unavailable:</comment> {$e->getMessage()}");
            }

            return $default;
        }

        return [
            'rows' => $snapshot['rows'] ?? null,
            'bytes' => $snapshot['bytes'] ?? null,
            'data_bytes' => $snapshot['data_bytes'] ?? null,
            'index_bytes' => $snapshot['index_bytes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function renderConfiguration(array $config): void
    {
        $this->line('<comment>Configuration:</comment>');

        $rows = [
            ['DB Connection', $config['db_connection'] ?? 'n/a', $config['db_table'] !== null ? "table: {$config['db_table']}" : ''],
            ['Queue Connection', $config['queue_connection'] ?? 'n/a', $config['queue_name'] !== null ? "queue: {$config['queue_name']}" : ''],
        ];

        $this->table(['Setting', 'Value', 'Detail'], $rows);
        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderCoverage(array $rows): void
    {
        $this->line('<comment>Model Coverage:</comment>');

        if (empty($rows)) {
            $this->line('  <fg=gray>No models found.</>');
            $this->newLine();

            return;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            if ($row['note'] !== null) {
                $tableRows[] = [
                    $row['model'],
                    'n/a',
                    'n/a',
                    "<fg=gray>{$row['note']}</>",
                ];

                continue;
            }

            $tableRows[] = [
                $row['model'],
                number_format($row['records']),
                number_format($row['with_payload']),
                $row['coverage'] === null ? 'n/a' : number_format($row['coverage'], 1) . '%',
            ];
        }

        $this->table(['Model', 'Records', 'With Payload', 'Coverage'], $tableRows);
        $this->newLine();
    }

    /**
     * @param  array{
     *     models_with_payload: int,
     *     payload_rows: int,
     *     stale_payload_records: int,
     *     embedded_entities_missing_payload: int,
     * }  $health
     */
    private function renderHealth(array $health): void
    {
        $this->line('<comment>Health:</comment>');
        $this->line('  Models with payload:                ' . number_format($health['models_with_payload']));
        $this->line('  Payload records:                    ' . number_format($health['payload_rows']));

        $stale = '  Stale payload records:              ' . number_format($health['stale_payload_records']);
        if ($health['stale_payload_records'] > 0) {
            $stale .= ' <fg=gray>→ Run </><info>embedding:payload:clean</info><fg=gray> to fix.</>';
        }
        $this->line($stale);

        $missing = '  Embedded entities missing payload:  ' . number_format($health['embedded_entities_missing_payload']);
        if ($health['embedded_entities_missing_payload'] > 0) {
            $missing .= ' <fg=gray>→ Run </><info>embedding:payload:sync</info><fg=gray> to backfill.</>';
        }
        $this->line($missing);
        $this->newLine();
    }

    /**
     * @param  array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}  $storage
     */
    private function renderStorage(array $storage): void
    {
        $this->line('<comment>Storage:</comment>');
        $this->line('  Rows:       ' . ($storage['rows'] === null ? 'n/a' : number_format($storage['rows'])));
        $this->line('  Data:       ' . $this->formatBytes($storage['data_bytes']));
        $this->line('  Index:      ' . $this->formatBytes($storage['index_bytes']));
        $this->line('  Total size: ' . $this->formatBytes($storage['bytes']));
        $this->newLine();
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        return Number::fileSize($bytes);
    }
}
