<?php

namespace XLaravel\Embedding\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use XLaravel\Embedding\BatchGenerator;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\TestCase;

class EligibleForEmbeddingTest extends TestCase
{
    public function test_missing_embedding_count_excludes_records_with_blank_slot_fields(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => 'Has content', 'body' => null]);
            Article::create(['title' => '', 'body' => null]);
        });

        $this->assertSame(1, Article::missingEmbeddingCount('default'));
    }

    public function test_missing_embedding_count_counts_record_eligible_if_any_slot_field_is_filled(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => '', 'body' => 'Body only, title blank']);
        });

        $this->assertSame(1, Article::missingEmbeddingCount('default'));
    }

    public function test_eligible_for_embedding_scope_filters_blank_records(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => 'Real', 'body' => null]);
            Article::create(['title' => '', 'body' => null]);
        });

        $this->assertSame(1, Article::eligibleForEmbedding('default')->count());
    }

    public function test_batch_generator_does_not_dispatch_jobs_for_blank_records(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => 'Has content', 'body' => null]);
            Article::create(['title' => '', 'body' => null]);
        });

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class);

        $this->assertNotNull($batch);
        $this->assertCount(1, $batch->added);
    }

    public function test_batch_generator_returns_null_when_only_blank_records_are_missing(): void
    {
        Article::withoutEmbedding(fn () => Article::create(['title' => '', 'body' => null]));

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class);

        $this->assertNull($batch);
    }
}
