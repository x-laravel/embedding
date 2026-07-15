<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadSoftDelete;
use XLaravel\Embedding\Tests\TestCase;

class CleanCommandTest extends TestCase
{
    public function test_fails_when_both_filter_options_combined(): void
    {
        $this->artisan('embedding:clean', [
            '--orphans-only' => true,
            '--invalid-slots-only' => true,
        ])
            ->expectsOutput('--orphans-only and --invalid-slots-only cannot be combined.')
            ->assertFailed();
    }

    public function test_reports_nothing_to_clean_on_empty_table(): void
    {
        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Nothing to clean.')
            ->assertSuccessful();
    }

    public function test_deletes_orphan_records_for_missing_class(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->assertDatabaseCount('embeddings', 2);

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
        $this->assertSame(0, Embedding::where('embeddable_type', 'App\\Models\\Ghost')->count());
    }

    public function test_deletes_orphan_records_for_deleted_models(): void
    {
        $article = Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);

        $article->forceDelete(); // observer also deletes its embedding

        // Re-create an orphan record manually to simulate stale data
        Embedding::create([
            'embeddable_type' => Article::class,
            'embeddable_id' => 9999,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->assertDatabaseCount('embeddings', 2);

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
        $this->assertSame(0, Embedding::where('embeddable_id', 9999)->count());
    }

    public function test_does_not_delete_embeddings_for_soft_deleted_models_when_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertTrue($article->hasEmbedding());

        $article->delete(); // soft-delete; observer keeps the embedding

        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => Article::class,
            'embeddable_id' => $article->id,
        ]);

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Nothing to clean.')
            ->assertSuccessful();

        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => Article::class,
            'embeddable_id' => $article->id,
        ]);
    }

    public function test_deletes_records_with_invalid_slot(): void
    {
        $post = PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => $post->id,
            'slot' => 'summary', // not in PostMultiSlot::embeddingSlotMap()
            'vector' => [0.1, 0.2],
        ]);

        $this->assertDatabaseCount('embeddings', 4);

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 3);
        $this->assertSame(0, Embedding::where('slot', 'summary')->count());
    }

    public function test_orphans_only_skips_invalid_slot_records(): void
    {
        $post = PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => $post->id,
            'slot' => 'summary',
            'vector' => [0.1, 0.2],
        ]);

        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->artisan('embedding:clean', ['--orphans-only' => true, '--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, Embedding::where('embeddable_type', 'App\\Models\\Ghost')->count());
        $this->assertSame(1, Embedding::where('slot', 'summary')->count());
    }

    public function test_invalid_slots_only_skips_orphans(): void
    {
        $post = PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => $post->id,
            'slot' => 'summary',
            'vector' => [0.1, 0.2],
        ]);

        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->artisan('embedding:clean', ['--invalid-slots-only' => true, '--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, Embedding::where('slot', 'summary')->count());
        $this->assertSame(1, Embedding::where('embeddable_type', 'App\\Models\\Ghost')->count());
    }

    public function test_dry_run_reports_findings_without_deleting(): void
    {
        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->artisan('embedding:clean', ['--dry-run' => true])
            ->expectsOutput('Dry-run: would delete 1 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
    }

    public function test_aborts_when_user_declines_confirmation(): void
    {
        Embedding::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->artisan('embedding:clean')
            ->expectsConfirmation('Delete 1 record(s)?', 'no')
            ->expectsOutput('Aborted.')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
    }

    public function test_fails_when_payload_only_combined_with_orphans_only(): void
    {
        $this->artisan('embedding:clean', [
            '--orphans-only' => true,
            '--payload-only' => true,
        ])
            ->expectsOutput('--orphans-only and --payload-only cannot be combined.')
            ->assertFailed();
    }

    public function test_deletes_payload_rows_for_missing_class(): void
    {
        EmbeddableRecord::create([
            'embeddable_type' => 'App\\Models\\Ghost',
            'embeddable_id' => 1,
            'payload' => ['province_id' => 1],
        ]);

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutputToContain('Stale payload records: 1')
            ->expectsOutput('Deleted 1 record(s).')
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

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
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

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_payload_only_skips_embedding_passes(): void
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

        $this->artisan('embedding:clean', ['--payload-only' => true, '--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
        $this->assertSame(1, Embedding::query()->count());
    }

    public function test_orphans_only_skips_payload_pass(): void
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

        $this->artisan('embedding:clean', ['--orphans-only' => true, '--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
        $this->assertSame(0, Embedding::query()->count());
    }

    public function test_keeps_payload_rows_for_soft_deleted_models_when_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'A', 'province_id' => 34]);
        $venue->delete(); // soft delete; observer keeps embedding + payload

        $this->assertSame(1, EmbeddableRecord::query()->count());

        $this->artisan('embedding:clean', ['--force' => true])
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

        $this->artisan('embedding:clean', ['--payload-only' => true, '--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
        $this->assertSame(
            1,
            EmbeddableRecord::query()->where('embeddable_id', $venue->getKey())->count(),
        );
    }

    public function test_deletes_orphan_records_when_model_lives_on_a_different_connection(): void
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

        $live = Article::create(['title' => 'Alive', 'body' => 'Yes']);
        $orphanId = $live->getKey() + 999;

        Embedding::create([
            'embeddable_type' => Article::class,
            'embeddable_id' => $orphanId,
            'slot' => 'default',
            'vector' => [0.1, 0.2],
        ]);

        $this->assertDatabaseCount('embeddings', 2, 'secondary');

        $this->artisan('embedding:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1, 'secondary');
        $this->assertSame(
            0,
            Embedding::where('embeddable_type', Article::class)
                ->where('embeddable_id', $orphanId)
                ->count()
        );
    }
}
