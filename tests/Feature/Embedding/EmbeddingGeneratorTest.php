<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use XLaravel\Embedding\EmbeddingGenerator;
use XLaravel\Embedding\Exceptions\EmptyEmbeddingTextException;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
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

    public function test_throws_empty_embedding_text_exception_when_resolved_text_is_blank(): void
    {
        $post = Post::create(['title' => '', 'body' => null]);

        $this->expectException(EmptyEmbeddingTextException::class);

        app(EmbeddingGenerator::class)->generate($post, 'default');
    }

    public function test_generate_model_embedding_job_swallows_blank_text_without_throwing(): void
    {
        $post = Post::create(['title' => '', 'body' => null]);

        (new GenerateModelEmbedding($post, 'default'))->handle(app(EmbeddingGenerator::class));

        $this->assertFalse($post->fresh()->hasEmbedding('default'));
    }
}
