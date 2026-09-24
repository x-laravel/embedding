<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use XLaravel\Embedding\Tests\Fixtures\Models\PostWithAttribute;
use XLaravel\Embedding\Tests\TestCase;

class EmbedOnAttributeTest extends TestCase
{
    public function test_embedding_is_created_on_model_creation_via_attribute(): void
    {
        $post = PostWithAttribute::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->hasEmbedding());
    }

    public function test_embedding_is_updated_when_attribute_defined_field_changes(): void
    {
        $post = PostWithAttribute::create(['title' => 'Hello', 'body' => 'World']);
        $firstVector = $post->fresh()->embedding->vector;

        $post->update(['title' => 'Updated']);

        $this->assertNotEquals($firstVector, $post->fresh()->embedding->vector);
    }

    public function test_embedding_is_not_updated_when_non_attribute_field_changes(): void
    {
        $post = PostWithAttribute::create(['title' => 'Hello', 'body' => 'World']);
        $embedding = $post->fresh()->embedding;

        $post->update(['status' => 'published']);

        $this->assertEquals($embedding->updated_at, $post->fresh()->embedding->updated_at);
    }
}
