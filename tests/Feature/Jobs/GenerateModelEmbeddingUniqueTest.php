<?php

namespace XLaravel\Embedding\Tests\Feature\Jobs;

use Illuminate\Support\Facades\Queue;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\TestCase;

class GenerateModelEmbeddingUniqueTest extends TestCase
{
    public function test_duplicate_dispatch_for_same_model_and_slot_is_skipped(): void
    {
        Queue::fake();

        $article = Article::withoutEmbedding(fn () => Article::create(['title' => 'A', 'body' => 'B']));

        GenerateModelEmbedding::dispatch($article, 'default');
        GenerateModelEmbedding::dispatch($article, 'default');

        Queue::assertPushed(GenerateModelEmbedding::class, 1);
    }

    public function test_different_slots_are_not_deduplicated(): void
    {
        Queue::fake();

        $article = Article::withoutEmbedding(fn () => Article::create(['title' => 'A', 'body' => 'B']));

        GenerateModelEmbedding::dispatch($article, 'default');
        GenerateModelEmbedding::dispatch($article, 'other-slot');

        Queue::assertPushed(GenerateModelEmbedding::class, 2);
    }

    public function test_different_records_are_not_deduplicated(): void
    {
        Queue::fake();

        [$first, $second] = Article::withoutEmbedding(fn () => [
            Article::create(['title' => 'A', 'body' => 'B']),
            Article::create(['title' => 'C', 'body' => 'D']),
        ]);

        GenerateModelEmbedding::dispatch($first, 'default');
        GenerateModelEmbedding::dispatch($second, 'default');

        Queue::assertPushed(GenerateModelEmbedding::class, 2);
    }
}
