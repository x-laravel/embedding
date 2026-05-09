<?php

namespace XLaravel\Embedding\Tests\Feature\Similarity;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\SimilarityDriver;
use XLaravel\Embedding\Similarity\PhpDriver;
use XLaravel\Embedding\SimilarityManager;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\TestCase;

class SimilarityTest extends TestCase
{
    public function test_auto_resolves_to_php_for_sqlite(): void
    {
        $manager = app(SimilarityManager::class);
        $manager->forgetDrivers();
        config(['embedding.similarity.driver' => 'auto']);

        $this->assertSame('php', $manager->getDefaultDriver());
        $this->assertInstanceOf(PhpDriver::class, $manager->driver());
    }

    public function test_explicit_php_driver_is_resolved(): void
    {
        $manager = app(SimilarityManager::class);
        $this->assertInstanceOf(PhpDriver::class, $manager->driver('php'));
    }

    public function test_php_driver_returns_sorted_results(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $queryVector = $post1->fresh()->embedding->vector;

        $results = Post::similarTo($queryVector, limit: 2);

        $this->assertCount(2, $results);
        $this->assertEquals($post1->id, $results->first()->id);
        $this->assertNotNull($results->first()->similarity_score);
    }

    public function test_custom_driver_can_be_registered_and_used(): void
    {
        $manager = app(SimilarityManager::class);

        $manager->extend('custom', function () {
            return new class implements SimilarityDriver {
                public function search(Model $prototype, array $queryVector, int $limit, float $threshold = 0.0, ?array $ids = null, string $slot = 'default'): Collection
                {
                    return new Collection();
                }
            };
        });

        Post::create(['title' => 'Hello', 'body' => 'World']);

        config(['embedding.similarity.driver' => 'custom']);
        $manager->forgetDrivers();

        $results = Post::similarTo([1.0, 0.0], limit: 10);

        $this->assertCount(0, $results);
    }

    public function test_php_driver_respects_threshold(): void
    {
        Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $vector = Post::first()->fresh()->embedding->vector;

        $results = Post::similarTo($vector, limit: 10, threshold: 2.0);

        $this->assertCount(0, $results);
    }

    public function test_php_driver_respects_where_closure(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $vector = $post1->fresh()->embedding->vector;

        $results = Post::similarTo($vector, limit: 10, where: fn ($q) => $q->where('id', $post2->id));

        $this->assertCount(1, $results);
        $this->assertEquals($post2->id, $results->first()->id);
    }

    public function test_similarity_to_model(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $post1->load('embedding');
        $post2->load('embedding');

        $score = $post1->similarityTo($post2);

        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(-1.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_similarity_to_self_is_one(): void
    {
        $post = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post->load('embedding');

        $score = $post->similarityTo($post->embedding->vector);

        $this->assertEqualsWithDelta(1.0, $score, 0.0001);
    }

    public function test_similarity_to_returns_zero_when_no_embedding(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = new Post(['title' => 'Python', 'body' => 'Django']);

        $post1->load('embedding');

        $score = $post1->similarityTo($post2);

        $this->assertSame(0.0, $score);
    }

    public function test_most_similar_excludes_self(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $post1->load('embedding');

        $results = $post1->mostSimilar(limit: 5);

        $this->assertNotContains($post1->id, $results->pluck('id')->all());
    }

    public function test_most_similar_returns_empty_when_no_embedding(): void
    {
        $post = new Post(['title' => 'PHP', 'body' => 'Laravel']);

        $results = $post->mostSimilar();

        $this->assertCount(0, $results);
    }

    public function test_similar_to_text(): void
    {
        Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $results = Post::similarToText('web framework', limit: 2);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);
        $this->assertNotNull($results->first()->similarity_score);
    }

    public function test_similar_to_text_with_threshold(): void
    {
        Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);

        $results = Post::similarToText('web framework', limit: 10, threshold: 2.0);

        $this->assertCount(0, $results);
    }

    public function test_rank_by_relevance_with_vector(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $post1->load('embedding');
        $queryVector = $post1->embedding->vector;

        $ranked = Post::rankByRelevance([$post1, $post2], $queryVector);

        $this->assertCount(2, $ranked);
        $this->assertEquals($post1->id, $ranked->first()->id);
        $this->assertNotNull($ranked->first()->similarity_score);
    }

    public function test_rank_by_relevance_with_string_query(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $ranked = Post::rankByRelevance(Post::all(), 'web development');

        $this->assertInstanceOf(Collection::class, $ranked);
        $this->assertCount(2, $ranked);
        $this->assertNotNull($ranked->first()->similarity_score);
    }

    public function test_rank_by_relevance_respects_threshold(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post1->load('embedding');

        $ranked = Post::rankByRelevance([$post1], $post1->embedding->vector, threshold: 2.0);

        $this->assertCount(0, $ranked);
    }
}
