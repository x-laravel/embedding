<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    private function statusJson(array $params = []): array
    {
        Artisan::call('embedding:status', $params + ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'embedding:status --json did not produce a JSON document.');

        return $payload;
    }

    public function test_reports_configuration_lines_with_driver_and_dimensions(): void
    {
        $payload = $this->statusJson();

        $this->assertSame('php', $payload['configuration']['similarity_driver']);
        $this->assertSame('auto', $payload['configuration']['similarity_driver_source']);
        $this->assertSame('sqlite', $payload['configuration']['auto_detected_from']);
        $this->assertSame(1536, $payload['configuration']['vector_dimensions']);
        $this->assertSame('sqlite', $payload['configuration']['db_connection']);
        $this->assertSame('embeddings', $payload['configuration']['db_table']);
        $this->assertSame('sync', $payload['configuration']['queue_connection']);
        $this->assertSame('embedding', $payload['configuration']['queue_name']);
    }

    public function test_marks_driver_as_forced_when_env_overrides_auto(): void
    {
        config(['embedding.similarity.driver' => 'php']);

        $payload = $this->statusJson();

        $this->assertSame('forced', $payload['configuration']['similarity_driver_source']);
        $this->assertNull($payload['configuration']['auto_detected_from']);
    }

    public function test_reports_zero_coverage_when_table_is_empty(): void
    {
        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertCount(1, $payload['models']);
        $this->assertSame(Article::class, $payload['models'][0]['model']);
        $this->assertSame(0, $payload['models'][0]['records']);
        $this->assertSame(0, $payload['models'][0]['embedded']);
        $this->assertNull($payload['models'][0]['coverage']);
        $this->assertSame(0, $payload['health']['total_vectors']);
    }

    public function test_reports_full_coverage_after_generation(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);

        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertCount(1, $payload['models']);
        $this->assertSame('default', $payload['models'][0]['slot']);
        $this->assertSame(2, $payload['models'][0]['records']);
        $this->assertSame(2, $payload['models'][0]['embedded']);
        $this->assertSame(100.0, $payload['models'][0]['coverage']);
        $this->assertSame(2, $payload['health']['total_vectors']);
    }

    public function test_reports_partial_coverage_for_multi_slot_model(): void
    {
        $post = PostMultiSlot::withoutEmbedding(
            fn () => PostMultiSlot::create(['title' => 'P', 'body' => 'p'])
        );

        // Only embed the title slot — body and full remain missing.
        $post->embedSync('title');

        $payload = $this->statusJson(['model' => PostMultiSlot::class]);

        $rowsBySlot = collect($payload['models'])->keyBy('slot');

        $this->assertSame(1, $rowsBySlot['title']['records']);
        $this->assertSame(1, $rowsBySlot['title']['embedded']);
        $this->assertSame(100.0, $rowsBySlot['title']['coverage']);

        $this->assertSame(1, $rowsBySlot['body']['records']);
        $this->assertSame(0, $rowsBySlot['body']['embedded']);
        $this->assertSame(0.0, $rowsBySlot['body']['coverage']);

        $this->assertSame(1, $rowsBySlot['full']['records']);
        $this->assertSame(0, $rowsBySlot['full']['embedded']);
        $this->assertSame(0.0, $rowsBySlot['full']['coverage']);
    }

    public function test_filters_to_single_slot_when_option_provided(): void
    {
        $post = PostMultiSlot::withoutEmbedding(
            fn () => PostMultiSlot::create(['title' => 'P', 'body' => 'p'])
        );
        $post->embedSync('title');

        $payload = $this->statusJson([
            'model' => PostMultiSlot::class,
            '--slot' => 'title',
        ]);

        $this->assertCount(1, $payload['models']);
        $this->assertSame('title', $payload['models'][0]['slot']);
        $this->assertSame(100.0, $payload['models'][0]['coverage']);
    }

    public function test_flags_undefined_slot_with_a_note(): void
    {
        $payload = $this->statusJson([
            'model' => PostMultiSlot::class,
            '--slot' => 'summary', // not declared on PostMultiSlot
        ]);

        $this->assertCount(1, $payload['models']);
        $this->assertSame('summary', $payload['models'][0]['slot']);
        $this->assertNull($payload['models'][0]['records']);
        $this->assertSame('slot not defined on model', $payload['models'][0]['note']);
    }

    public function test_health_section_counts_orphan_records(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertSame(1, $payload['health']['orphan_records']);
        $this->assertSame(0, $payload['health']['invalid_slot_records']);
        $this->assertSame(2, $payload['health']['total_vectors']);
    }

    public function test_health_section_counts_invalid_slot_records(): void
    {
        $post = PostMultiSlot::withoutEmbedding(
            fn () => PostMultiSlot::create(['title' => 'P', 'body' => 'p'])
        );

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => $post->id,
            'slot' => 'summary',
            'vector' => [0.1, 0.2],
        ]);

        $payload = $this->statusJson(['model' => PostMultiSlot::class]);

        $this->assertSame(0, $payload['health']['orphan_records']);
        $this->assertSame(1, $payload['health']['invalid_slot_records']);
    }

    public function test_default_metrics_returns_eloquent_count_for_rows_and_null_bytes(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);

        $payload = $this->statusJson();

        $this->assertSame(2, $payload['storage']['rows']);
        $this->assertNull($payload['storage']['bytes']);
        $this->assertNull($payload['storage']['data_bytes']);
        $this->assertNull($payload['storage']['index_bytes']);

        // Human-readable rendering should print "n/a" for the unsupported byte fields.
        $this->artisan('embedding:status')
            ->expectsOutputToContain('Total size: n/a')
            ->assertSuccessful();
    }

    public function test_storage_falls_back_to_na_when_metrics_implementation_throws(): void
    {
        $this->app->bind(VectorStoreMetrics::class, fn () => new class implements VectorStoreMetrics {
            public function snapshot(): array
            {
                throw new RuntimeException('storage backend unreachable');
            }
        });

        $payload = $this->statusJson();

        $this->assertNull($payload['storage']['rows']);
        $this->assertNull($payload['storage']['bytes']);
        $this->assertNull($payload['storage']['data_bytes']);
        $this->assertNull($payload['storage']['index_bytes']);
    }

    public function test_storage_uses_metrics_when_bound(): void
    {
        $this->app->bind(VectorStoreMetrics::class, fn () => new class implements VectorStoreMetrics {
            public function snapshot(): array
            {
                return [
                    'rows' => 2950,
                    'bytes' => 130023424,
                    'data_bytes' => 110003200,
                    'index_bytes' => 20020224,
                ];
            }
        });

        $payload = $this->statusJson();

        $this->assertSame(2950, $payload['storage']['rows']);
        $this->assertSame(130023424, $payload['storage']['bytes']);
        $this->assertSame(110003200, $payload['storage']['data_bytes']);
        $this->assertSame(20020224, $payload['storage']['index_bytes']);
    }

    public function test_json_flag_emits_machine_readable_output(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertArrayHasKey('configuration', $payload);
        $this->assertArrayHasKey('ai', $payload);
        $this->assertArrayHasKey('models', $payload);
        $this->assertArrayHasKey('health', $payload);
        $this->assertArrayHasKey('storage', $payload);

        $this->assertSame(1, $payload['health']['total_vectors']);
    }

    public function test_ai_services_section_reports_configured_provider_and_default_model(): void
    {
        $payload = $this->statusJson();

        $this->assertSame('openai', $payload['ai']['embedding']['provider']);
        $this->assertIsString($payload['ai']['embedding']['model']);
        $this->assertNotSame('', $payload['ai']['embedding']['model']);

        $this->assertSame('cohere', $payload['ai']['rerank']['provider']);
        $this->assertIsString($payload['ai']['rerank']['model']);
        $this->assertNotSame('', $payload['ai']['rerank']['model']);
    }

    public function test_ai_services_section_falls_back_to_na_when_provider_unconfigured(): void
    {
        config([
            'ai.default_for_embeddings' => null,
            'ai.default_for_reranking' => null,
        ]);

        $payload = $this->statusJson();

        $this->assertNull($payload['ai']['embedding']['provider']);
        $this->assertNull($payload['ai']['embedding']['model']);
        $this->assertNull($payload['ai']['rerank']['provider']);
        $this->assertNull($payload['ai']['rerank']['model']);

        $this->artisan('embedding:status')
            ->expectsOutputToContain('Embedding Provider')
            ->assertSuccessful();
    }

    public function test_invalid_model_class_returns_failure(): void
    {
        $this->artisan('embedding:status', ['model' => 'App\\Models\\DoesNotExist'])
            ->expectsOutput('Class [App\\Models\\DoesNotExist] does not exist.')
            ->assertFailed();
    }

    public function test_rejects_model_that_does_not_implement_has_embeddings(): void
    {
        $this->artisan('embedding:status', ['model' => \Illuminate\Database\Eloquent\Model::class])
            ->expectsOutput('Class [Illuminate\\Database\\Eloquent\\Model] does not implement HasEmbeddings.')
            ->assertFailed();
    }

    public function test_health_section_handles_cross_connection_models(): void
    {
        config([
            'database.connections.secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'embedding.database.connection' => 'secondary',
        ]);

        \Illuminate\Support\Facades\Schema::connection('secondary')->create('embeddings', function ($table) {
            $table->id();
            $table->morphs('embeddable');
            $table->string('slot', 64)->default('default');
            $table->json('vector');
            $table->timestamps();
            $table->unique(['embeddable_type', 'embeddable_id', 'slot']);
        });

        $live = Article::create(['title' => 'Alive', 'body' => 'Yes']);
        $orphanId = $live->getKey() + 999;

        // Orphan record on the embeddings (secondary) connection — its
        // embeddable_id has no matching row on the model (default) connection.
        Embedding::create([
            'embeddable_type' => Article::class,
            'embeddable_id' => $orphanId,
            'slot' => 'default',
            'vector' => [0.1, 0.2, 0.3],
        ]);

        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertSame(1, $payload['health']['orphan_records']);
        $this->assertSame(0, $payload['health']['invalid_slot_records']);
    }
}
