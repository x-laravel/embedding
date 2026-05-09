<?php

namespace XLaravel\Embedding\Tests\Feature\Reranking;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use XLaravel\Embedding\Reranker;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\TestCase;

class RerankTest extends TestCase
{
    public function test_macro_reorders_collection_by_provided_scores(): void
    {
        Reranking::fake(fn ($prompt) => [
            new RankedDocument(index: 2, document: $prompt->documents[2], score: 0.94),
            new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.71),
            new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.42),
        ]);

        $a = Post::create(['title' => 'A', 'body' => 'apples']);
        $b = Post::create(['title' => 'B', 'body' => 'bananas']);
        $c = Post::create(['title' => 'C', 'body' => 'citrus']);

        $reranked = (new EloquentCollection([$a, $b, $c]))->rerankWithScores('which fruit is yellow?');

        $this->assertCount(3, $reranked);
        $this->assertSame([$c->id, $a->id, $b->id], $reranked->pluck('id')->all());
        $this->assertEqualsWithDelta(0.94, $reranked[0]->rerank_score, 0.0001);
        $this->assertEqualsWithDelta(0.71, $reranked[1]->rerank_score, 0.0001);
        $this->assertEqualsWithDelta(0.42, $reranked[2]->rerank_score, 0.0001);
    }

    public function test_take_parameter_passes_limit_to_provider(): void
    {
        Reranking::fake(function ($prompt) {
            $this->assertSame(2, $prompt->limit);

            return [
                new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.9),
                new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.8),
            ];
        });

        $a = Post::create(['title' => 'A', 'body' => 'first']);
        $b = Post::create(['title' => 'B', 'body' => 'second']);
        $c = Post::create(['title' => 'C', 'body' => 'third']);

        $reranked = (new EloquentCollection([$a, $b, $c]))
            ->rerankWithScores('q', take: 2);

        $this->assertCount(2, $reranked);
        $this->assertSame([$b->id, $a->id], $reranked->pluck('id')->all());
    }

    public function test_threshold_filters_low_scoring_results_locally(): void
    {
        Reranking::fake(fn ($prompt) => [
            new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.91),
            new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.62),
            new RankedDocument(index: 2, document: $prompt->documents[2], score: 0.18),
        ]);

        $posts = EloquentCollection::make([
            Post::create(['title' => 'A', 'body' => 'a']),
            Post::create(['title' => 'B', 'body' => 'b']),
            Post::create(['title' => 'C', 'body' => 'c']),
        ]);

        $reranked = $posts->rerankWithScores('q', threshold: 0.5);

        $this->assertCount(2, $reranked);
        $this->assertGreaterThanOrEqual(0.5, $reranked->min('rerank_score'));
    }

    public function test_field_overrides_to_embedding_text(): void
    {
        Reranking::fake(function ($prompt) {
            $this->assertSame(['apples', 'bananas'], $prompt->documents);

            return [
                new RankedDocument(index: 0, document: 'apples', score: 0.8),
                new RankedDocument(index: 1, document: 'bananas', score: 0.6),
            ];
        });

        $posts = EloquentCollection::make([
            Post::create(['title' => 'Title One', 'body' => 'apples']),
            Post::create(['title' => 'Title Two', 'body' => 'bananas']),
        ]);

        $posts->rerankWithScores('q', field: 'body');
    }

    public function test_default_uses_to_embedding_text(): void
    {
        Reranking::fake(function ($prompt) {
            $this->assertSame(
                ['Title One apples', 'Title Two bananas'],
                $prompt->documents,
            );

            return [
                new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.8),
                new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.6),
            ];
        });

        $posts = EloquentCollection::make([
            Post::create(['title' => 'Title One', 'body' => 'apples']),
            Post::create(['title' => 'Title Two', 'body' => 'bananas']),
        ]);

        $posts->rerankWithScores('q');
    }

    public function test_empty_collection_skips_api_call(): void
    {
        Reranking::fake();

        $reranked = (new EloquentCollection())->rerankWithScores('q');

        $this->assertCount(0, $reranked);
        Reranking::assertNothingReranked();
    }

    public function test_single_item_skips_api_call(): void
    {
        Reranking::fake();

        $only = Post::create(['title' => 'A', 'body' => 'a']);
        $reranked = (new EloquentCollection([$only]))->rerankWithScores('q');

        $this->assertCount(1, $reranked);
        $this->assertNull($reranked[0]->getAttribute('rerank_score'));
        Reranking::assertNothingReranked();
    }

    public function test_similarity_score_is_preserved(): void
    {
        Reranking::fake(fn ($prompt) => [
            new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.9),
            new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.6),
        ]);

        $a = Post::create(['title' => 'A', 'body' => 'a']);
        $a->setAttribute('similarity_score', 0.7);
        $b = Post::create(['title' => 'B', 'body' => 'b']);
        $b->setAttribute('similarity_score', 0.5);

        $reranked = (new EloquentCollection([$a, $b]))->rerankWithScores('q');

        $this->assertEqualsWithDelta(0.5, $reranked[0]->similarity_score, 0.0001);
        $this->assertEqualsWithDelta(0.9, $reranked[0]->rerank_score, 0.0001);
        $this->assertEqualsWithDelta(0.7, $reranked[1]->similarity_score, 0.0001);
        $this->assertEqualsWithDelta(0.6, $reranked[1]->rerank_score, 0.0001);
    }

    public function test_reranker_can_be_resolved_from_container(): void
    {
        Reranking::fake(fn ($prompt) => [
            new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.5),
            new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.4),
        ]);

        $a = Post::create(['title' => 'A', 'body' => 'a']);
        $b = Post::create(['title' => 'B', 'body' => 'b']);

        $reranked = app(Reranker::class)->rerank(
            new EloquentCollection([$a, $b]),
            query: 'q',
        );

        $this->assertCount(2, $reranked);
        $this->assertEqualsWithDelta(0.5, $reranked[0]->rerank_score, 0.0001);
    }
}
