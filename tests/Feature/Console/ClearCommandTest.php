<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\TestCase;

class ClearCommandTest extends TestCase
{
    public function test_fails_when_neither_model_nor_all_is_given(): void
    {
        $this->artisan('embedding:clear')
            ->expectsOutput('Provide a model class or use --all.')
            ->assertFailed();
    }

    public function test_fails_when_model_is_combined_with_all(): void
    {
        $this->artisan('embedding:clear', ['model' => Article::class, '--all' => true])
            ->expectsOutput('The [model] argument cannot be combined with --all.')
            ->assertFailed();
    }

    public function test_fails_for_nonexistent_class(): void
    {
        $this->artisan('embedding:clear', ['model' => 'App\\Models\\Missing'])
            ->expectsOutput('Class [App\\Models\\Missing] does not exist.')
            ->assertFailed();
    }

    public function test_clears_all_embeddings_for_a_model(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);
        PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        $this->assertDatabaseCount('embeddings', 5); // 2 articles + 3 slots

        $this->artisan('embedding:clear', ['model' => Article::class, '--force' => true])
            ->expectsOutput('Deleted 2 embedding(s) for ['.Article::class.'].')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 3);
        $this->assertSame(0, Embedding::where('embeddable_type', Article::class)->count());
    }

    public function test_clears_only_a_specific_slot_for_a_model(): void
    {
        $post = PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        $this->assertDatabaseCount('embeddings', 3);

        $this->artisan('embedding:clear', [
            'model' => PostMultiSlot::class,
            '--slot' => 'title',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 2);
        $this->assertFalse($post->fresh()->hasEmbedding('title'));
        $this->assertTrue($post->fresh()->hasEmbedding('body'));
        $this->assertTrue($post->fresh()->hasEmbedding('full'));
    }

    public function test_clears_entire_table_with_all(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        PostMultiSlot::create(['title' => 'P', 'body' => 'p']);

        $this->assertDatabaseCount('embeddings', 4);

        $this->artisan('embedding:clear', ['--all' => true, '--force' => true])
            ->expectsOutput('Deleted 4 embedding(s) from the entire embeddings table.')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_clears_a_slot_across_all_models_with_all(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']); // slot=default
        PostMultiSlot::create(['title' => 'P', 'body' => 'p']); // title/body/full

        $this->artisan('embedding:clear', [
            '--all' => true,
            '--slot' => 'title',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Embedding::where('slot', 'title')->count());
        $this->assertSame(3, Embedding::count()); // article default + body + full
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);
        Article::create(['title' => 'B', 'body' => 'b']);

        $this->artisan('embedding:clear', [
            'model' => Article::class,
            '--dry-run' => true,
        ])
            ->expectsOutput('Dry-run: would delete 2 embedding(s) for ['.Article::class.'].')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 2);
    }

    public function test_reports_zero_when_nothing_matches(): void
    {
        $this->artisan('embedding:clear', ['model' => Article::class, '--force' => true])
            ->expectsOutput('No embeddings to delete for ['.Article::class.'].')
            ->assertSuccessful();
    }

    public function test_aborts_when_user_declines_confirmation(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        $this->artisan('embedding:clear', ['model' => Article::class])
            ->expectsConfirmation('Delete 1 embedding(s) for ['.Article::class.']?', 'no')
            ->expectsOutput('Aborted.')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 1);
    }
}
