<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Payload;

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    public function test_backfills_missing_payload_rows(): void
    {
        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34, 'category_id' => 3])
        );
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'B', 'province_id' => 6])
        );

        $this->assertSame(0, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:sync', ['model' => VenueWithPayload::class])
            ->expectsOutput('Synced payload for 2 record(s).')
            ->assertSuccessful();

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $record = EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayload::class)
            ->where('embeddable_id', $venue->id)
            ->first();
        $this->assertSame(34, $record->payload['province_id']);
        $this->assertSame(3, $record->payload['category_id']);

        // The vector side is never touched.
        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_sync_is_idempotent(): void
    {
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $this->artisan('embedding:payload:sync', ['model' => VenueWithPayload::class])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $this->artisan('embedding:payload:sync', ['model' => VenueWithPayload::class])
            ->expectsOutput('Synced payload for 0 record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_sync_makes_no_ai_call(): void
    {
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $aiCalls = 0;

        Embeddings::fake(function () use (&$aiCalls) {
            $aiCalls++;

            return null;
        });

        $this->artisan('embedding:payload:sync', ['model' => VenueWithPayload::class])
            ->assertSuccessful();

        $this->assertSame(0, $aiCalls);
    }

    public function test_dry_run_reports_counts_without_writing(): void
    {
        VenueWithPayload::withoutEmbedding(function () {
            for ($i = 0; $i < 3; $i++) {
                VenueWithPayload::create(['name' => "V{$i}", 'province_id' => $i]);
            }
        });

        $this->artisan('embedding:payload:sync', [
            'model' => VenueWithPayload::class,
            '--dry-run' => true,
        ])
            ->expectsOutput('Dry-run: would sync payload for 3 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_force_refreshes_existing_rows(): void
    {
        $venue = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $tampered = EmbeddableRecord::query()->where('embeddable_id', $venue->id)->first();
        $tampered->payload = ['province_id' => 99];
        $tampered->save();

        $this->artisan('embedding:payload:sync', [
            'model' => VenueWithPayload::class,
            '--force' => true,
        ])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $record = EmbeddableRecord::query()->where('embeddable_id', $venue->id)->first();
        $this->assertSame(34, $record->payload['province_id']);
    }

    public function test_warns_for_model_without_payload_definition(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        $this->artisan('embedding:payload:sync', ['model' => Article::class])
            ->expectsOutput('No embedding payload defined on ['.Article::class.'].')
            ->expectsOutput('Synced payload for 0 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_fails_for_nonexistent_class(): void
    {
        $this->artisan('embedding:payload:sync', ['model' => 'App\Models\NonExistent'])
            ->expectsOutput('Class [App\Models\NonExistent] does not exist.')
            ->assertFailed();
    }

    public function test_limit_caps_records_processed(): void
    {
        VenueWithPayload::withoutEmbedding(function () {
            for ($i = 0; $i < 5; $i++) {
                VenueWithPayload::create(['name' => "V{$i}", 'province_id' => $i]);
            }
        });

        $this->artisan('embedding:payload:sync', [
            'model' => VenueWithPayload::class,
            '--limit' => 2,
        ])
            ->expectsOutput('Synced payload for 2 record(s).')
            ->assertSuccessful();

        $this->assertSame(2, EmbeddableRecord::query()->count());
    }

    public function test_backfills_when_payload_lives_on_a_different_connection(): void
    {
        config([
            'database.connections.secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'embedding.database.connection' => 'secondary',
        ]);

        Schema::connection('secondary')->create('embeddables', function ($table) {
            $table->id();
            $table->morphs('embeddable');
            $table->json('payload');
            $table->timestamps();
            $table->unique(['embeddable_type', 'embeddable_id']);
        });

        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $this->artisan('embedding:payload:sync', ['model' => VenueWithPayload::class])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $record = EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayload::class)
            ->where('embeddable_id', $venue->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(34, $record->payload['province_id']);
        $this->assertSame('secondary', $record->getConnection()->getName());
    }
}
