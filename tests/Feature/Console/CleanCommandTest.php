<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
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
            ->expectsOutput('Deleted 1 embedding(s).')
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
            ->expectsOutput('Deleted 1 embedding(s).')
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
            ->expectsOutput('Deleted 1 embedding(s).')
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
            ->expectsOutput('Deleted 1 embedding(s).')
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
            ->expectsOutput('Deleted 1 embedding(s).')
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
            ->expectsOutput('Dry-run: would delete 1 embedding(s).')
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
            ->expectsConfirmation('Delete 1 embedding(s)?', 'no')
            ->expectsOutput('Aborted.')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
    }
}
