<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Events\ModelEmbedded;
use XLaravel\Embedding\Events\ModelEmbedding;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\PostAllFields;
use XLaravel\Embedding\Tests\Fixtures\Models\PostNoEmbedding;
use XLaravel\Embedding\Tests\TestCase;

class EmbeddableTest extends TestCase
{
    public function test_embedding_is_created_on_model_creation(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->hasEmbedding());
        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => Post::class,
            'embeddable_id' => $post->id,
        ]);
    }

    public function test_embedding_is_updated_when_embeddable_field_changes(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        $firstVector = $post->fresh()->embedding->vector;

        $post->update(['title' => 'Updated Title']);

        $secondVector = $post->fresh()->embedding->vector;
        $this->assertNotEquals($firstVector, $secondVector);
    }

    public function test_embedding_is_not_updated_when_non_embeddable_field_changes(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        $embedding = $post->fresh()->embedding;

        $post->update(['status' => 'published']);

        $this->assertEquals($embedding->updated_at, $post->fresh()->embedding->updated_at);
    }

    public function test_embedding_is_not_created_when_embeddable_is_empty(): void
    {
        $post = PostNoEmbedding::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertFalse($post->hasEmbedding());
        $this->assertDatabaseMissing('embeddings', [
            'embeddable_type' => PostNoEmbedding::class,
            'embeddable_id' => $post->id,
        ]);
    }

    public function test_embedding_is_created_on_any_change_when_embeddable_is_wildcard(): void
    {
        $post = PostAllFields::create(['title' => 'Hello', 'status' => 'draft']);
        $this->assertTrue($post->hasEmbedding());

        $firstVector = $post->fresh()->embedding->vector;
        $post->update(['status' => 'published']);

        $this->assertNotEquals($firstVector, $post->fresh()->embedding->vector);
    }

    public function test_has_embedding_returns_false_before_embedding_is_created(): void
    {
        Post::disableEmbedding();
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        Post::enableEmbedding();

        $this->assertFalse($post->hasEmbedding());
    }

    public function test_without_embedding_prevents_automatic_embedding(): void
    {
        Post::withoutEmbedding(function () {
            Post::create(['title' => 'Hello', 'body' => 'World']);
        });

        $this->assertDatabaseMissing('embeddings', ['embeddable_type' => Post::class]);
    }

    public function test_without_embedding_re_enables_after_callback(): void
    {
        Post::withoutEmbedding(fn () => null);

        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertTrue($post->hasEmbedding());
    }

    public function test_embedding_is_deleted_when_model_is_deleted(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertDatabaseHas('embeddings', ['embeddable_id' => $post->id]);

        $post->delete();

        $this->assertDatabaseMissing('embeddings', ['embeddable_id' => $post->id]);
    }

    public function test_embed_sync_generates_embedding_immediately(): void
    {
        Post::disableEmbedding();
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);
        Post::enableEmbedding();

        $this->assertFalse($post->hasEmbedding());

        $post->embedSync();

        $this->assertTrue($post->fresh()->hasEmbedding());
    }

    public function test_model_embedding_event_is_fired(): void
    {
        Event::fake([ModelEmbedding::class, ModelEmbedded::class]);

        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        Event::assertDispatched(ModelEmbedding::class, fn ($e) => $e->model->is($post));
        Event::assertDispatched(ModelEmbedded::class, fn ($e) => $e->model->is($post));
    }

    public function test_eloquent_embedding_model_event_can_be_registered(): void
    {
        $fired = false;

        Post::onEmbedding(function () use (&$fired) {
            $fired = true;
        });

        Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($fired);
    }

    public function test_eloquent_embedded_model_event_can_be_registered(): void
    {
        $fired = false;

        Post::onEmbedded(function () use (&$fired) {
            $fired = true;
        });

        Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($fired);
    }

    public function test_vector_is_stored_as_float_array(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $vector = $post->fresh()->embedding->vector;

        $this->assertIsArray($vector);
        $this->assertNotEmpty($vector);
        $this->assertIsFloat($vector[0]);
    }

    public function test_similar_to_returns_sorted_collection(): void
    {
        $post1 = Post::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = Post::create(['title' => 'Python', 'body' => 'Django framework']);

        $queryVector = $post1->fresh()->embedding->vector;

        $results = Post::similarTo($queryVector, limit: 2);

        $this->assertCount(2, $results);
        $this->assertEquals($post1->id, $results->first()->id);
    }

}
