<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Payload;

use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadSoftDelete;
use XLaravel\Embedding\Tests\TestCase;

class CleanCommandTest extends TestCase
{
    public function test_reports_nothing_to_clean_on_empty_table(): void
    {
        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Nothing to clean.')
            ->assertSuccessful();
    }

    public function test_deletes_payload_rows_for_missing_class(): void
    {
        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutputToContain('Stale payload records: 1')
            ->expectsOutput('Deleted 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_deletes_payload_rows_for_deleted_models(): void
    {
        $venue = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        // Stale row for an entity that no longer exists on the model side.
        EmbeddableRecord::create([
            'embeddable_type' => VenueWithPayload::class,
            'embeddable_id' => 9999,
            'payload' => ['province_id' => 1],
        ]);

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
        $this->assertSame(
            1,
            EmbeddableRecord::query()->where('embeddable_id', $venue->id)->count(),
        );
    }

    public function test_deletes_payload_rows_when_model_no_longer_defines_payload(): void
    {
        $article = Article::create(['title' => 'A', 'body' => 'a']);

        // Article has no payload definition — a leftover row is stale.
        EmbeddableRecord::create([
            'embeddable_type' => Article::class,
            'embeddable_id' => $article->id,
            'payload' => ['old' => 'value'],
        ]);

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_never_touches_vector_embeddings(): void
    {
        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());

        // Orphan vectors belong to embedding:vector:clean.
        $this->assertSame(1, Embedding::query()->count());
    }

    public function test_dry_run_reports_findings_without_deleting(): void
    {
        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $this->artisan('embedding:payload:clean', ['--dry-run' => true])
            ->expectsOutput('Dry-run: would delete 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_aborts_when_user_declines_confirmation(): void
    {
        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $this->artisan('embedding:payload:clean')
            ->expectsConfirmation('Delete 1 payload record(s)?', 'no')
            ->expectsOutput('Aborted.')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_keeps_payload_rows_for_soft_deleted_models_when_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'A', 'province_id' => 34]);
        $venue->delete(); // soft delete; observer keeps embedding + payload

        $this->assertSame(1, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Nothing to clean.')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_deletes_payload_rows_when_model_lives_on_a_different_connection(): void
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

        $venue = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        EmbeddableRecord::create([
            'embeddable_type' => VenueWithPayload::class,
            'embeddable_id' => $venue->getKey() + 999,
            'payload' => ['province_id' => 1],
        ]);

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 payload record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
        $this->assertSame(
            1,
            EmbeddableRecord::query()->where('embeddable_id', $venue->getKey())->count(),
        );
    }
}
