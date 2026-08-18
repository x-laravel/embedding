<?php

namespace XLaravel\Embedding\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use XLaravel\Embedding\BatchGenerator;
use XLaravel\Embedding\Support\SlotQueryPlanner;
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

    /**
     * A raw column can be non-blank (passes eligibleForEmbedding's SQL
     * check) yet still resolve to blank text once toEmbeddingText() has
     * run — e.g. whitespace-only content, or a model that strips
     * placeholder punctuation. missingEmbeddingCount() must catch this via
     * the resolved-text filter, not just the raw-column scope.
     */
    public function test_missing_embedding_count_excludes_records_whose_resolved_text_is_blank_despite_non_blank_raw_columns(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => ' ', 'body' => null]);
            Article::create(['title' => 'Real content', 'body' => null]);
        });

        $this->assertSame(1, Article::missingEmbeddingCount('default'));
    }

    public function test_slot_query_planner_missing_ids_excludes_resolved_blank_records(): void
    {
        $whitespaceOnly = Article::withoutEmbedding(fn () => Article::create(['title' => ' ', 'body' => null]));
        $real = Article::withoutEmbedding(fn () => Article::create(['title' => 'Real content', 'body' => null]));

        $ids = SlotQueryPlanner::missingIds(Article::class, 'default');

        $this->assertContains($real->id, $ids);
        $this->assertNotContains($whitespaceOnly->id, $ids);
    }

    public function test_batch_generator_does_not_dispatch_for_resolved_blank_records(): void
    {
        Article::withoutEmbedding(function () {
            Article::create(['title' => ' ', 'body' => null]);
            Article::create(['title' => 'Real content', 'body' => null]);
        });

        Bus::fake();

        $batch = app(BatchGenerator::class)->dispatch(Article::class);

        $this->assertNotNull($batch);
        $this->assertCount(1, $batch->added);
    }
}
