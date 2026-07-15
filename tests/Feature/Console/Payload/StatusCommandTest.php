<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Payload;

use Illuminate\Support\Facades\Artisan;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueMultiSlotWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class StatusCommandTest extends TestCase
{
    private function statusJson(array $params = []): array
    {
        Artisan::call('embedding:payload:status', $params + ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'embedding:payload:status --json did not produce a JSON document.');

        return $payload;
    }

    public function test_reports_configuration(): void
    {
        $payload = $this->statusJson();

        $this->assertSame('sqlite', $payload['configuration']['db_connection']);
        $this->assertSame('embeddables', $payload['configuration']['db_table']);
        $this->assertSame('sync', $payload['configuration']['queue_connection']);
        $this->assertSame('embedding.sync-payload', $payload['configuration']['queue_name']);
    }

    public function test_reports_full_coverage(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'B', 'province_id' => 6]);

        $payload = $this->statusJson(['model' => VenueWithPayload::class]);

        $this->assertCount(1, $payload['models']);
        $this->assertSame(VenueWithPayload::class, $payload['models'][0]['model']);
        $this->assertSame(2, $payload['models'][0]['records']);
        $this->assertSame(2, $payload['models'][0]['with_payload']);
        $this->assertSame(100.0, $payload['models'][0]['coverage']);

        $this->assertSame(1, $payload['health']['models_with_payload']);
        $this->assertSame(2, $payload['health']['payload_rows']);
        $this->assertSame(0, $payload['health']['stale_payload_records']);
        $this->assertSame(0, $payload['health']['embedded_entities_missing_payload']);
    }

    public function test_reports_partial_coverage(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'B', 'province_id' => 6])
        );

        $payload = $this->statusJson(['model' => VenueWithPayload::class]);

        $this->assertSame(2, $payload['models'][0]['records']);
        $this->assertSame(1, $payload['models'][0]['with_payload']);
        $this->assertSame(50.0, $payload['models'][0]['coverage']);
    }

    public function test_flags_model_without_payload_definition(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        $payload = $this->statusJson(['model' => Article::class]);

        $this->assertCount(1, $payload['models']);
        $this->assertNull($payload['models'][0]['records']);
        $this->assertSame('no payload defined', $payload['models'][0]['note']);

        $this->assertSame(0, $payload['health']['models_with_payload']);
    }

    public function test_health_counts_stale_payload_records(): void
    {
        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $payload = $this->statusJson();

        $this->assertSame(1, $payload['health']['stale_payload_records']);

        // The human-readable rendering points at the clean command.
        $this->artisan('embedding:payload:status')
            ->expectsOutputToContain('embedding:payload:clean')
            ->assertSuccessful();
    }

    public function test_health_counts_embedded_entities_missing_payload(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        EmbeddableRecord::query()->delete();

        $payload = $this->statusJson(['model' => VenueWithPayload::class]);

        $this->assertSame(1, $payload['health']['models_with_payload']);
        $this->assertSame(0, $payload['health']['payload_rows']);
        $this->assertSame(1, $payload['health']['embedded_entities_missing_payload']);

        // The human-readable rendering points at the backfill command.
        $this->artisan('embedding:payload:status', ['model' => VenueWithPayload::class])
            ->expectsOutputToContain('embedding:payload:sync')
            ->assertSuccessful();
    }

    public function test_missing_payload_count_is_per_entity_not_per_slot(): void
    {
        // Two slots → two embeddings, but a single entity and a single
        // (deleted) payload row: the missing count must be 1, not 2.
        VenueMultiSlotWithPayload::create([
            'name' => 'A', 'description' => 'desc', 'province_id' => 34,
        ]);

        $this->assertSame(2, Embedding::query()->count());

        EmbeddableRecord::query()->delete();

        $payload = $this->statusJson(['model' => VenueMultiSlotWithPayload::class]);

        $this->assertSame(1, $payload['health']['embedded_entities_missing_payload']);
    }

    public function test_invalid_model_class_returns_failure(): void
    {
        $this->artisan('embedding:payload:status', ['model' => 'App\\Models\\DoesNotExist'])
            ->expectsOutput('Class [App\\Models\\DoesNotExist] does not exist.')
            ->assertFailed();
    }

    public function test_rejects_model_that_does_not_implement_has_embeddings(): void
    {
        $this->artisan('embedding:payload:status', ['model' => \Illuminate\Database\Eloquent\Model::class])
            ->expectsOutput('Class [Illuminate\\Database\\Eloquent\\Model] does not implement HasEmbeddings.')
            ->assertFailed();
    }

    public function test_coverage_handles_cross_connection_models(): void
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

        \Illuminate\Support\Facades\Schema::connection('secondary')->create('embeddables', function ($table) {
            $table->id();
            $table->morphs('embeddable');
            $table->json('payload');
            $table->timestamps();
            $table->unique(['embeddable_type', 'embeddable_id']);
        });

        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'B', 'province_id' => 6])
        );

        $payload = $this->statusJson(['model' => VenueWithPayload::class]);

        $this->assertSame(2, $payload['models'][0]['records']);
        $this->assertSame(1, $payload['models'][0]['with_payload']);
        $this->assertSame(50.0, $payload['models'][0]['coverage']);
    }
}
