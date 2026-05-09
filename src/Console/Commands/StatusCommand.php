<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\SimilarityManager;

class StatusCommand extends Command
{
    protected $signature = 'embedding:status
        {model? : Restrict the report to a single HasEmbeddings model class}
        {--slot= : Restrict the report to a single slot}
        {--json : Emit a single JSON object suitable for CI / monitoring}';

    protected $description = 'Show a read-only health report for the embeddings table (configuration, coverage, orphans, storage size).';

    public function handle(): int
    {
        $models = $this->resolveModels();

        if ($models === null) {
            return self::FAILURE;
        }

        $configuration = $this->collectConfiguration();
        $coverage = $this->collectCoverage($models);
        $health = $this->collectHealth();
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
     * @return array{
     *     similarity_driver: string,
     *     similarity_driver_source: string,
     *     auto_detected_from: string|null,
     *     vector_dimensions: int,
     *     db_connection: string|null,
     *     db_table: string|null,
     *     queue_connection: string|null,
     *     queue_name: string|null,
     * }
     */
    private function collectConfiguration(): array
    {
        $configured = (string) config('embedding.similarity.driver', 'auto');

        /** @var SimilarityManager $manager */
        $manager = $this->laravel->make(SimilarityManager::class);
        $resolved = $manager->getDefaultDriver();

        $autoFrom = null;
        if ($configured === 'auto') {
            try {
                $autoFrom = DB::connection(config('embedding.database.connection'))->getDriverName();
            } catch (Throwable) {
                $autoFrom = null;
            }
        }

        return [
            'similarity_driver' => $resolved,
            'similarity_driver_source' => $configured === 'auto' ? 'auto' : 'forced',
            'auto_detected_from' => $autoFrom,
            'vector_dimensions' => (int) config('embedding.dimensions'),
            'db_connection' => config('embedding.database.connection'),
            'db_table' => config('embedding.database.table'),
            'queue_connection' => config('embedding.queue.connection'),
            'queue_name' => config('embedding.queue.name'),
        ];
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function collectCoverage(array $models): array
    {
        $rows = [];
        $slotFilter = $this->option('slot');

        foreach ($models as $modelClass) {
            $instance = new $modelClass();
            $slotMap = $instance->embeddingSlotMap();

            if (empty($slotMap)) {
                $rows[] = [
                    'model' => $modelClass,
                    'slot' => null,
                    'records' => null,
                    'embedded' => null,
                    'coverage' => null,
                    'note' => 'no slots defined',
                ];

                continue;
            }

            $slots = $slotFilter !== null ? [$slotFilter] : array_keys($slotMap);

            foreach ($slots as $slot) {
                if ($slotFilter !== null && ! array_key_exists($slot, $slotMap)) {
                    $rows[] = [
                        'model' => $modelClass,
                        'slot' => $slot,
                        'records' => null,
                        'embedded' => null,
                        'coverage' => null,
                        'note' => 'slot not defined on model',
                    ];

                    continue;
                }

                $total = $modelClass::query()->count();
                $embedded = $this->countEmbeddedForSlot($modelClass, $slot);
                $coverage = $total > 0 ? round($embedded / $total * 100, 1) : null;

                $rows[] = [
                    'model' => $modelClass,
                    'slot' => $slot,
                    'records' => $total,
                    'embedded' => $embedded,
                    'coverage' => $coverage,
                    'note' => null,
                ];
            }
        }

        return $rows;
    }

    private function countEmbeddedForSlot(string $modelClass, string $slot): int
    {
        $modelConnection = (new $modelClass())->getConnection()->getName();
        $embeddingConnection = (new Embedding())->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            return $modelClass::whereHas(
                'embeddings',
                fn ($q) => $q->where('slot', $slot)
            )->count();
        }

        // Cross-connection — pluck the (usually small) embedding-side ID
        // list for this slot first, then verify them against the model
        // side. Avoids piping the full model table through an IN clause
        // when most rows have no embedding.
        $embeddingIds = Embedding::query()
            ->where('embeddable_type', $modelClass)
            ->where('slot', $slot)
            ->pluck('embeddable_id')
            ->all();

        if (empty($embeddingIds)) {
            return 0;
        }

        $instance = new $modelClass();

        return $modelClass::query()
            ->whereIn($instance->getKeyName(), $embeddingIds)
            ->count();
    }

    /**
     * @return array{orphan_records: int, invalid_slot_records: int, total_vectors: int}
     */
    private function collectHealth(): array
    {
        return [
            'orphan_records' => $this->countOrphans(),
            'invalid_slot_records' => $this->countInvalidSlots(),
            'total_vectors' => Embedding::query()->count(),
        ];
    }

    private function countOrphans(): int
    {
        $sum = 0;

        $types = Embedding::query()
            ->select('embeddable_type')
            ->distinct()
            ->pluck('embeddable_type');

        foreach ($types as $type) {
            $type = (string) $type;

            if (! class_exists($type)) {
                $sum += Embedding::query()->where('embeddable_type', $type)->count();

                continue;
            }

            $instance = new $type();
            $modelConnection = $instance->getConnection()->getName();
            $embeddingConnection = (new Embedding())->getConnection()->getName();

            if ($modelConnection === $embeddingConnection) {
                $modelTable = $instance->getTable();
                $modelKey = $instance->getKeyName();
                $embeddingTable = (new Embedding())->getTable();

                $sum += Embedding::query()
                    ->where('embeddable_type', $type)
                    ->whereNotExists(function ($q) use ($modelTable, $modelKey, $embeddingTable) {
                        $q->selectRaw('1')
                            ->from($modelTable)
                            ->whereColumn(
                                "{$modelTable}.{$modelKey}",
                                "{$embeddingTable}.embeddable_id",
                            );
                    })
                    ->count();

                continue;
            }

            // Cross-connection — JOIN-style subqueries do not work across
            // databases. Pluck the (usually small) distinct embeddable_id
            // set from the embedding side, verify which still exist on the
            // model side, and count the difference. Reverses the naive
            // direction (model → embedding) so we never ship a multi-thousand
            // IN clause to the embedding database for types whose model
            // table is large but barely embedded.
            $distinctEmbeddedIds = Embedding::query()
                ->where('embeddable_type', $type)
                ->distinct()
                ->pluck('embeddable_id')
                ->all();

            if (empty($distinctEmbeddedIds)) {
                continue;
            }

            $existingIds = $instance->getConnection()
                ->table($instance->getTable())
                ->whereIn($instance->getKeyName(), $distinctEmbeddedIds)
                ->pluck($instance->getKeyName())
                ->all();

            $orphanIds = array_values(array_diff($distinctEmbeddedIds, $existingIds));

            if (empty($orphanIds)) {
                continue;
            }

            $sum += Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('embeddable_id', $orphanIds)
                ->count();
        }

        return $sum;
    }

    private function countInvalidSlots(): int
    {
        $rows = Embedding::query()
            ->select('embeddable_type', 'slot')
            ->distinct()
            ->get();

        $slotsByType = [];
        foreach ($rows as $row) {
            $slotsByType[$row->embeddable_type][] = $row->slot;
        }

        $sum = 0;

        foreach ($slotsByType as $type => $slots) {
            if (! class_exists($type) || ! is_a($type, HasEmbeddings::class, true)) {
                continue;
            }

            $validSlots = array_keys((new $type())->embeddingSlotMap());

            if (empty($validSlots)) {
                continue;
            }

            $invalidSlots = array_values(array_diff($slots, $validSlots));

            if (empty($invalidSlots)) {
                continue;
            }

            $instance = new $type();
            $modelConnection = $instance->getConnection()->getName();
            $embeddingConnection = (new Embedding())->getConnection()->getName();

            if ($modelConnection === $embeddingConnection) {
                $modelTable = $instance->getTable();
                $modelKey = $instance->getKeyName();
                $embeddingTable = (new Embedding())->getTable();

                $sum += Embedding::query()
                    ->where('embeddable_type', $type)
                    ->whereIn('slot', $invalidSlots)
                    ->whereExists(function ($q) use ($modelTable, $modelKey, $embeddingTable) {
                        $q->selectRaw('1')
                            ->from($modelTable)
                            ->whereColumn(
                                "{$modelTable}.{$modelKey}",
                                "{$embeddingTable}.embeddable_id",
                            );
                    })
                    ->count();

                continue;
            }

            // Cross-connection — pluck the candidate IDs straight from the
            // embedding side filtered by the invalid slot list, then verify
            // existence on the model side. Same direction reversal as
            // countOrphans() to keep IN clauses small.
            $candidateIds = Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots)
                ->distinct()
                ->pluck('embeddable_id')
                ->all();

            if (empty($candidateIds)) {
                continue;
            }

            $existingIds = $instance->getConnection()
                ->table($instance->getTable())
                ->whereIn($instance->getKeyName(), $candidateIds)
                ->pluck($instance->getKeyName())
                ->all();

            if (empty($existingIds)) {
                continue;
            }

            $sum += Embedding::query()
                ->where('embeddable_type', $type)
                ->whereIn('slot', $invalidSlots)
                ->whereIn('embeddable_id', $existingIds)
                ->count();
        }

        return $sum;
    }

    /**
     * @return array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}
     */
    private function collectStorage(): array
    {
        $default = ['rows' => null, 'bytes' => null, 'data_bytes' => null, 'index_bytes' => null];

        if (! $this->laravel->bound(VectorStoreMetrics::class)) {
            return $default;
        }

        try {
            $snapshot = $this->laravel->make(VectorStoreMetrics::class)->snapshot();
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

        $similarity = $config['similarity_driver'];
        if ($config['similarity_driver_source'] === 'auto' && $config['auto_detected_from'] !== null) {
            $similarity .= " <fg=gray>(auto-detected from {$config['auto_detected_from']})</>";
        } elseif ($config['similarity_driver_source'] === 'forced') {
            $similarity .= ' <fg=gray>(forced via EMBEDDING_SIMILARITY_DRIVER)</>';
        }

        $this->line("  Similarity Driver: {$similarity}");
        $this->line("  Vector Dimensions: {$config['vector_dimensions']}");
        $this->line("  DB Connection:     {$config['db_connection']} <fg=gray>(table: {$config['db_table']})</>");
        $this->line("  Queue Connection:  {$config['queue_connection']} <fg=gray>(queue: {$config['queue_name']})</>");
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
                    $row['slot'] ?? 'n/a',
                    'n/a',
                    'n/a',
                    "<fg=gray>{$row['note']}</>",
                ];

                continue;
            }

            $tableRows[] = [
                $row['model'],
                $row['slot'],
                number_format($row['records']),
                number_format($row['embedded']),
                $row['coverage'] === null ? 'n/a' : number_format($row['coverage'], 1) . '%',
            ];
        }

        $this->table(['Model', 'Slot', 'Records', 'Embedded', 'Coverage'], $tableRows);
        $this->newLine();
    }

    /**
     * @param  array{orphan_records: int, invalid_slot_records: int, total_vectors: int}  $health
     */
    private function renderHealth(array $health): void
    {
        $this->line('<comment>Health:</comment>');
        $hint = ' <fg=gray>→ Run </><info>embedding:clean</info><fg=gray> to fix.</>';

        $orphan = '  Orphan records (missing models):    ' . number_format($health['orphan_records']);
        if ($health['orphan_records'] > 0) {
            $orphan .= $hint;
        }
        $this->line($orphan);

        $invalid = '  Invalid slots (stale definitions):  ' . number_format($health['invalid_slot_records']);
        if ($health['invalid_slot_records'] > 0) {
            $invalid .= $hint;
        }
        $this->line($invalid);

        $this->line('  Total stored vectors:               ' . number_format($health['total_vectors']));
        $this->newLine();
    }

    /**
     * @param  array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}  $storage
     */
    private function renderStorage(array $storage): void
    {
        $this->line('<comment>Storage:</comment>');
        $this->line('  Rows:       ' . ($storage['rows'] === null ? 'n/a' : number_format($storage['rows'])));
        $this->line('  Total size: ' . $this->formatBytes($storage['bytes']));
        $this->line('  Data:       ' . $this->formatBytes($storage['data_bytes']));
        $this->line('  Index:      ' . $this->formatBytes($storage['index_bytes']));
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
