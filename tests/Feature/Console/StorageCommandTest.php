<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class StorageCommandTest extends TestCase
{
    private function storageJson(): array
    {
        Artisan::call('embedding:storage', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'embedding:storage --json did not produce a JSON document.');

        return $payload;
    }

    public function test_default_metrics_report_row_counts_and_null_bytes(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);
        VenueWithPayload::create(['name' => 'V', 'province_id' => 34]);

        $payload = $this->storageJson();

        $this->assertSame(3, $payload['vector']['rows']); // 2 articles + 1 venue
        $this->assertNull($payload['vector']['bytes']);

        $this->assertSame(1, $payload['payload']['rows']);
        $this->assertNull($payload['payload']['bytes']);
    }

    public function test_renders_both_sections_and_na_combined_total_without_byte_figures(): void
    {
        $this->artisan('embedding:storage')
            ->expectsOutputToContain('Vector storage (embeddings):')
            ->expectsOutputToContain('Payload storage (embeddables):')
            ->expectsOutputToContain('Combined total: n/a')
            ->assertSuccessful();
    }

    public function test_combined_total_sums_bytes_when_both_drivers_supply_them(): void
    {
        $this->app->bind(VectorStoreMetrics::class, fn () => new class implements VectorStoreMetrics {
            public function snapshot(): array
            {
                return ['rows' => 100, 'bytes' => 3145728, 'data_bytes' => 2097152, 'index_bytes' => 1048576];
            }
        });

        $this->app->bind(PayloadStoreMetrics::class, fn () => new class implements PayloadStoreMetrics {
            public function snapshot(): array
            {
                return ['rows' => 100, 'bytes' => 1048576, 'data_bytes' => 786432, 'index_bytes' => 262144];
            }
        });

        $payload = $this->storageJson();

        $this->assertSame(3145728, $payload['vector']['bytes']);
        $this->assertSame(1048576, $payload['payload']['bytes']);

        $this->artisan('embedding:storage')
            ->expectsOutputToContain('Combined total: 4 MB')
            ->assertSuccessful();
    }

    public function test_combined_total_is_na_when_one_side_cannot_supply_bytes(): void
    {
        // Vector driver supplies bytes, payload (default binding) cannot —
        // a partial sum would silently understate the total.
        $this->app->bind(VectorStoreMetrics::class, fn () => new class implements VectorStoreMetrics {
            public function snapshot(): array
            {
                return ['rows' => 100, 'bytes' => 3145728, 'data_bytes' => null, 'index_bytes' => null];
            }
        });

        $this->artisan('embedding:storage')
            ->expectsOutputToContain('Combined total: n/a')
            ->assertSuccessful();
    }

    public function test_falls_back_to_na_when_metrics_implementation_throws(): void
    {
        $this->app->bind(VectorStoreMetrics::class, fn () => new class implements VectorStoreMetrics {
            public function snapshot(): array
            {
                throw new RuntimeException('storage backend unreachable');
            }
        });

        $payload = $this->storageJson();

        $this->assertNull($payload['vector']['rows']);
        $this->assertNull($payload['vector']['bytes']);

        // The payload side still reports through its own binding.
        $this->assertSame(0, $payload['payload']['rows']);

        $this->artisan('embedding:storage')->assertSuccessful();
    }
}
