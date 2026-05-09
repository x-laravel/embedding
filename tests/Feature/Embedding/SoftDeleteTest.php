<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\ArticleKeepEmbedding;
use XLaravel\Embedding\Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    public function test_embedding_is_deleted_on_soft_delete_by_default(): void
    {
        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertTrue($article->hasEmbedding());

        $article->delete();

        $this->assertDatabaseMissing('embeddings', ['embeddable_id' => $article->id]);
    }

    public function test_embedding_is_kept_on_soft_delete_when_soft_delete_enabled(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertTrue($article->hasEmbedding());

        $article->delete();

        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => Article::class,
            'embeddable_id' => $article->id,
        ]);
    }

    public function test_embedding_is_regenerated_on_restore_when_soft_delete_disabled(): void
    {
        config(['embedding.soft_delete' => false]);

        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $article->delete();
        $this->assertDatabaseMissing('embeddings', ['embeddable_id' => $article->id]);

        $article->restore();

        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => Article::class,
            'embeddable_id' => $article->id,
        ]);
    }

    public function test_embedding_is_not_regenerated_on_restore_when_soft_delete_enabled(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $originalVector = $article->fresh()->embedding->vector;

        $article->delete();
        $article->restore();

        $this->assertEquals($originalVector, $article->fresh()->embedding->vector);
    }

    public function test_per_model_property_keeps_embedding_on_soft_delete_regardless_of_config(): void
    {
        config(['embedding.soft_delete' => false]);

        $article = ArticleKeepEmbedding::create(['title' => 'Hello', 'body' => 'World']);
        $article->delete();

        $this->assertDatabaseHas('embeddings', [
            'embeddable_type' => ArticleKeepEmbedding::class,
            'embeddable_id' => $article->id,
        ]);
    }

    public function test_per_model_property_regenerates_on_restore_regardless_of_config(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = ArticleKeepEmbedding::create(['title' => 'Hello', 'body' => 'World']);
        $originalVector = $article->fresh()->embedding->vector;

        $article->delete();
        $article->restore();

        $this->assertEquals($originalVector, $article->fresh()->embedding->vector);
    }

    public function test_embedding_is_deleted_on_force_delete_regardless_of_config(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $this->assertTrue($article->hasEmbedding());

        $article->forceDelete();

        $this->assertDatabaseMissing('embeddings', ['embeddable_id' => $article->id]);
    }
}
