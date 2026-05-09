<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Support\Facades\Event;
use XLaravel\Embedding\Events\ModelEmbedded;
use XLaravel\Embedding\Events\ModelEmbedding;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\Fixtures\Models\PostWithMultiSlotAttribute;
use XLaravel\Embedding\Tests\TestCase;

class MultiSlotTest extends TestCase
{
    public function test_all_slots_are_created_on_model_creation(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->hasEmbedding('title'));
        $this->assertTrue($post->hasEmbedding('body'));
        $this->assertTrue($post->hasEmbedding('full'));
        $this->assertDatabaseCount('embeddings', 3);
    }

    public function test_only_title_and_full_slots_regenerated_when_title_changes(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $titleVector = $post->fresh()->embedding('title')->first()->vector;
        $bodyVector = $post->fresh()->embedding('body')->first()->vector;
        $fullVector = $post->fresh()->embedding('full')->first()->vector;

        $post->update(['title' => 'Updated']);

        $this->assertNotEquals($titleVector, $post->fresh()->embedding('title')->first()->vector);
        $this->assertNotEquals($fullVector, $post->fresh()->embedding('full')->first()->vector);
        // body is unchanged — its vector should stay the same
        $this->assertEquals($bodyVector, $post->fresh()->embedding('body')->first()->vector);
    }

    public function test_only_body_and_full_slots_regenerated_when_body_changes(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $titleVector = $post->fresh()->embedding('title')->first()->vector;
        $bodyVector = $post->fresh()->embedding('body')->first()->vector;
        $fullVector = $post->fresh()->embedding('full')->first()->vector;

        $post->update(['body' => 'Updated body']);

        $this->assertEquals($titleVector, $post->fresh()->embedding('title')->first()->vector);
        $this->assertNotEquals($bodyVector, $post->fresh()->embedding('body')->first()->vector);
        $this->assertNotEquals($fullVector, $post->fresh()->embedding('full')->first()->vector);
    }

    public function test_no_slot_is_regenerated_when_non_embeddable_field_changes(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $titleUpdatedAt = $post->fresh()->embedding('title')->first()->updated_at;
        $bodyUpdatedAt = $post->fresh()->embedding('body')->first()->updated_at;
        $fullUpdatedAt = $post->fresh()->embedding('full')->first()->updated_at;

        $post->update(['status' => 'published']);

        $this->assertEquals($titleUpdatedAt, $post->fresh()->embedding('title')->first()->updated_at);
        $this->assertEquals($bodyUpdatedAt, $post->fresh()->embedding('body')->first()->updated_at);
        $this->assertEquals($fullUpdatedAt, $post->fresh()->embedding('full')->first()->updated_at);
    }

    public function test_embedding_slot_map_returns_all_slots(): void
    {
        $post = new PostMultiSlot();
        $map = $post->embeddingSlotMap();

        $this->assertArrayHasKey('title', $map);
        $this->assertArrayHasKey('body', $map);
        $this->assertArrayHasKey('full', $map);

        $this->assertSame(['title'], $map['title']);
        $this->assertSame(['body'], $map['body']);
        $this->assertSame(['title', 'body'], $map['full']);
    }

    public function test_slots_to_embed_selects_correct_slots_for_changed_fields(): void
    {
        $post = PostMultiSlot::create(['title' => 'A', 'body' => 'B']);
        $post->update(['title' => 'Changed']);

        $slots = $post->slotsToEmbed(['title']);

        $this->assertContains('title', $slots);
        $this->assertContains('full', $slots);
        $this->assertNotContains('body', $slots);
    }

    public function test_similarity_search_uses_specified_slot(): void
    {
        $post1 = PostMultiSlot::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = PostMultiSlot::create(['title' => 'Python', 'body' => 'Django framework']);

        $titleVector = $post1->fresh()->embedding('title')->first()->vector;

        $results = PostMultiSlot::similarTo($titleVector, limit: 2, slot: 'title');

        $this->assertCount(2, $results);
        $this->assertEquals($post1->id, $results->first()->id);
    }

    public function test_has_embedding_checks_specific_slot(): void
    {
        PostMultiSlot::disableEmbedding();
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);
        PostMultiSlot::enableEmbedding();

        $this->assertFalse($post->hasEmbedding('title'));
        $this->assertFalse($post->hasEmbedding('body'));
        $this->assertFalse($post->hasEmbedding('full'));

        $post->embedSync('title');

        $this->assertTrue($post->fresh()->hasEmbedding('title'));
        $this->assertFalse($post->fresh()->hasEmbedding('body'));
    }

    public function test_events_carry_slot_name(): void
    {
        Event::fake([ModelEmbedding::class, ModelEmbedded::class]);

        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        Event::assertDispatched(ModelEmbedding::class, fn ($e) => $e->slot === 'title');
        Event::assertDispatched(ModelEmbedding::class, fn ($e) => $e->slot === 'body');
        Event::assertDispatched(ModelEmbedding::class, fn ($e) => $e->slot === 'full');
        Event::assertDispatched(ModelEmbedded::class, fn ($e) => $e->slot === 'full' && $e->model->is($post));
    }

    public function test_on_embedded_callback_receives_slot(): void
    {
        $receivedSlots = [];

        PostMultiSlot::onEmbedded(function ($model, $slot) use (&$receivedSlots) {
            $receivedSlots[] = $slot;
        });

        PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertContains('title', $receivedSlots);
        $this->assertContains('body', $receivedSlots);
        $this->assertContains('full', $receivedSlots);
    }

    public function test_all_slots_deleted_when_model_is_deleted(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertDatabaseCount('embeddings', 3);

        $post->delete();

        $this->assertDatabaseMissing('embeddings', ['embeddable_id' => $post->id]);
    }

    public function test_embed_on_attribute_with_multiple_slots(): void
    {
        $post = PostWithMultiSlotAttribute::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->hasEmbedding('title'));
        $this->assertTrue($post->hasEmbedding('body'));
        $this->assertTrue($post->hasEmbedding('full'));
    }

    public function test_embed_on_attribute_slot_map_is_correct(): void
    {
        $post = new PostWithMultiSlotAttribute();
        $map = $post->embeddingSlotMap();

        $this->assertSame(['title'], $map['title']);
        $this->assertSame(['body'], $map['body']);
        $this->assertSame(['title', 'body'], $map['full']);
    }

    public function test_artisan_command_generates_specific_slot_only(): void
    {
        PostMultiSlot::disableEmbedding();
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);
        PostMultiSlot::enableEmbedding();

        $this->assertDatabaseCount('embeddings', 0);

        $this->artisan('embedding:generate', [
            'model' => PostMultiSlot::class,
            '--slot' => 'title',
        ])->expectsOutput('Generated embeddings for 1 record(s).')->assertSuccessful();

        $this->assertTrue($post->fresh()->hasEmbedding('title'));
        $this->assertFalse($post->fresh()->hasEmbedding('body'));
        $this->assertFalse($post->fresh()->hasEmbedding('full'));
    }

    public function test_artisan_command_generates_all_slots_when_no_slot_specified(): void
    {
        PostMultiSlot::disableEmbedding();
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);
        PostMultiSlot::enableEmbedding();

        $this->assertDatabaseCount('embeddings', 0);

        $this->artisan('embedding:generate', ['model' => PostMultiSlot::class])
            ->expectsOutput('Generated embeddings for 3 record(s).')
            ->assertSuccessful();

        $this->assertTrue($post->fresh()->hasEmbedding('title'));
        $this->assertTrue($post->fresh()->hasEmbedding('body'));
        $this->assertTrue($post->fresh()->hasEmbedding('full'));
    }

    public function test_most_similar_uses_specified_slot(): void
    {
        $post1 = PostMultiSlot::create(['title' => 'PHP', 'body' => 'Laravel framework']);
        $post2 = PostMultiSlot::create(['title' => 'Python', 'body' => 'Django framework']);

        $results = $post1->mostSimilar(limit: 5, slot: 'title');

        $this->assertNotContains($post1->id, $results->pluck('id')->all());
        $this->assertCount(1, $results);
    }
}
