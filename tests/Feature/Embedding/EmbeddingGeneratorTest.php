<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use XLaravel\Embedding\EmbeddingGenerator;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\TestCase;

class EmbeddingGeneratorTest extends TestCase
{
    public function test_single_slot_model_rejects_non_default_slot_name(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Slot 'title' was requested");

        app(EmbeddingGenerator::class)->generate($post, 'title');
    }

    public function test_single_slot_model_accepts_default_slot(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $embedding = app(EmbeddingGenerator::class)->generate($post, 'default');

        $this->assertSame('default', $embedding->slot);
    }

    public function test_multi_slot_model_rejects_unknown_slot_name(): void
    {
        $post = PostMultiSlot::create(['title' => 'Hello', 'body' => 'World']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Slot 'summary' was requested");

        app(EmbeddingGenerator::class)->generate($post, 'summary');
    }
}
