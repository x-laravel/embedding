<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Throwable;

trait ReadsStorageMetrics
{
    /**
     * Read a metrics contract (VectorStoreMetrics / PayloadStoreMetrics)
     * defensively: an unbound contract or a throwing implementation yields
     * an all-null snapshot instead of failing the command.
     *
     * @param  class-string  $contract
     * @return array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}
     */
    private function storageSnapshot(string $contract): array
    {
        $default = ['rows' => null, 'bytes' => null, 'data_bytes' => null, 'index_bytes' => null];

        if (! $this->laravel->bound($contract)) {
            return $default;
        }

        try {
            $snapshot = $this->laravel->make($contract)->snapshot();
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
     * @param  array{rows: int|null, bytes: int|null, data_bytes: int|null, index_bytes: int|null}  $storage
     */
    private function renderStorageLines(array $storage): void
    {
        $this->line('  Rows:       ' . ($storage['rows'] === null ? 'n/a' : number_format($storage['rows'])));
        $this->line('  Data:       ' . $this->formatBytes($storage['data_bytes']));
        $this->line('  Index:      ' . $this->formatBytes($storage['index_bytes']));
        $this->line('  Total size: ' . $this->formatBytes($storage['bytes']));
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        // Number::fileSize() requires ext-intl, which the package does not
        // depend on — format manually instead.
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $formatted = number_format($value, 2);

        // Trim insignificant trailing zeros: "4.00" → "4", "104.93" stays.
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return "{$formatted} {$units[$unit]}";
    }
}
