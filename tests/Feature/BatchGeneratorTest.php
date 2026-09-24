<?php

namespace XLaravel\Embedding\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use XLaravel\Embedding\BatchGenerator;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\TestCase;

class BatchGeneratorTest extends TestCase
{
    public function test_dispatches_a_batch_for_missing_embeddings_only(): void
    {
        // Real (sync) dispatch here so this article genuinely has an embedding.
        Article::create(['title' => 'Has', 'body' => 'Embedding']);
        Article::withoutEmbedding(fn () => Article::create(['title' => 'No', 'body' => 'Embedding']));

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class);

        $this->assertNotNull($batch);
        $this->assertCount(1, $batch->added);
        $this->assertInstanceOf(GenerateModelEmbedding::class, $batch->added[0]);
    }

    public function test_returns_null_when_nothing_is_missing(): void
    {
        Article::create(['title' => 'Has', 'body' => 'Embedding']);

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class);

        $this->assertNull($batch);
        Bus::assertNothingBatched();
    }

    public function test_force_option_regenerates_existing_embeddings_too(): void
    {
        Article::create(['title' => 'First', 'body' => 'Body']);
        Article::create(['title' => 'Second', 'body' => 'Body']);

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class, force: true);

        $this->assertNotNull($batch);
        $this->assertCount(2, $batch->added);
    }

    public function test_finally_callback_is_registered_on_the_pending_batch(): void
    {
        Article::withoutEmbedding(fn () => Article::create(['title' => 'No', 'body' => 'Embedding']));

        Bus::fake();

        app(BatchGenerator::class)->dispatch(Article::class, finally: fn () => null);

        Bus::assertBatched(fn ($pendingBatch) => count($pendingBatch->finallyCallbacks()) === 1);
    }
}
