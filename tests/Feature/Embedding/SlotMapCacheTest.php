<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\TestCase;

class SlotMapCacheTest extends TestCase
{
    public function test_slot_map_is_memoized_per_class(): void
    {
        $post = new Post();

        $first = $post->embeddingSlotMap();
        $second = $post->embeddingSlotMap();

        $this->assertSame($first, $second);
        $this->assertSame(['default' => ['title', 'body']], $first);
    }

    public function test_subclasses_have_independent_cache_entries(): void
    {
        $post = new Post();
        $multi = new PostMultiSlot();

        $this->assertSame(['default' => ['title', 'body']], $post->embeddingSlotMap());
        $this->assertSame(
            [
                'title' => ['title'],
                'body' => ['body'],
                'full' => ['title', 'body'],
            ],
            $multi->embeddingSlotMap(),
        );
    }

    public function test_flush_drops_only_calling_classs_cache(): void
    {
        $post = new Post();
        $multi = new PostMultiSlot();

        $post->embeddingSlotMap();
        $multi->embeddingSlotMap();

        Post::flushEmbeddingSlotMapCache();

        // Both classes still resolve correctly; the flush only invalidates
        // Post's entry, not PostMultiSlot's.
        $this->assertSame(['default' => ['title', 'body']], $post->embeddingSlotMap());
        $this->assertSame(
            [
                'title' => ['title'],
                'body' => ['body'],
                'full' => ['title', 'body'],
            ],
            $multi->embeddingSlotMap(),
        );
    }
}
