<?php

namespace XLaravel\Embedding\Console\Commands;

use Illuminate\Console\Command;
use XLaravel\Embedding\Console\Commands\Concerns\ReadsStorageMetrics;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;

class StorageCommand extends Command
{
    use ReadsStorageMetrics;

    protected $signature = 'embedding:storage
        {--json : Emit a single JSON object suitable for CI / monitoring}';

    protected $description = 'Show storage figures for the embeddings and embeddables tables — two metrics reads, no coverage or health scans (those live in the status commands).';

    public function handle(): int
    {
        $vector = $this->storageSnapshot(VectorStoreMetrics::class);
        $payload = $this->storageSnapshot(PayloadStoreMetrics::class);

        if ($this->option('json')) {
            $this->line(json_encode([
                'vector' => $vector,
                'payload' => $payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $this->line('<comment>Vector storage (embeddings):</comment>');
        $this->renderStorageLines($vector);
        $this->newLine();

        $this->line('<comment>Payload storage (embeddables):</comment>');
        $this->renderStorageLines($payload);
        $this->newLine();

        // A combined figure only makes sense when both drivers can supply
        // one — a null on either side would silently understate the total.
        $combined = $vector['bytes'] !== null && $payload['bytes'] !== null
            ? $vector['bytes'] + $payload['bytes']
            : null;
        $this->line('Combined total: ' . $this->formatBytes($combined));

        return self::SUCCESS;
    }
}
