<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Support\Facades\Artisan;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Support\MissingPayloadResolver;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\ArticleKeepEmbedding;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadSoftDelete;
use XLaravel\Embedding\Tests\TestCase;

class SoftDeletedSubjectsTest extends TestCase
{
    public function test_trashed_record_without_embedding_counts_as_missing_when_embeddings_are_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $this->trashedArticleWithoutEmbedding();

        $this->assertSame(1, Article::missingEmbeddingCount());
    }

    public function test_trashed_record_is_ignored_when_embeddings_are_not_kept(): void
    {
        config(['embedding.soft_delete' => false]);

        $this->trashedArticleWithoutEmbedding();

        $this->assertSame(0, Article::missingEmbeddingCount());
    }

    public function test_per_model_override_includes_trashed_records_regardless_of_config(): void
    {
        config(['embedding.soft_delete' => false]);

        $article = ArticleKeepEmbedding::withoutEmbedding(fn () => ArticleKeepEmbedding::create(['title' => 'Hello', 'body' => 'World']));
        $article->delete();

        $this->assertSame(1, ArticleKeepEmbedding::missingEmbeddingCount());
    }

    public function test_generate_command_embeds_trashed_records_when_embeddings_are_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $article = $this->trashedArticleWithoutEmbedding();

        $this->artisan('embedding:vector:generate', ['model' => Article::class])->assertSuccessful();

        $this->assertTrue($this->hasEmbedding($article->id));
    }

    public function test_generate_command_skips_trashed_records_when_embeddings_are_not_kept(): void
    {
        config(['embedding.soft_delete' => false]);

        $article = $this->trashedArticleWithoutEmbedding();

        $this->artisan('embedding:vector:generate', ['model' => Article::class])->assertSuccessful();

        $this->assertFalse($this->hasEmbedding($article->id));
    }

    public function test_embedded_count_follows_the_soft_delete_setting(): void
    {
        config(['embedding.soft_delete' => true]);

        Article::create(['title' => 'Hello', 'body' => 'World'])->delete();

        $this->assertSame(1, Article::embeddedCount());

        config(['embedding.soft_delete' => false]);

        $this->assertSame(0, Article::embeddedCount());
    }

    public function test_status_counts_trashed_records_only_when_embeddings_are_kept(): void
    {
        Article::create(['title' => 'Live', 'body' => 'one']);
        $this->trashedArticleWithoutEmbedding();

        config(['embedding.soft_delete' => true]);
        $kept = $this->statusRow();

        config(['embedding.soft_delete' => false]);
        $dropped = $this->statusRow();

        $this->assertSame([2, 1], [$kept['records'], $kept['embedded']]);
        $this->assertSame([1, 1], [$dropped['records'], $dropped['embedded']]);
    }

    public function test_clean_removes_embeddings_of_trashed_records_when_embeddings_are_not_kept(): void
    {
        config(['embedding.soft_delete' => true]);
        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $article->delete();

        config(['embedding.soft_delete' => false]);
        $this->artisan('embedding:vector:clean', ['--force' => true])->assertSuccessful();

        $this->assertFalse($this->hasEmbedding($article->id));
    }

    public function test_clean_keeps_embeddings_of_trashed_records_when_embeddings_are_kept(): void
    {
        config(['embedding.soft_delete' => true]);
        $article = Article::create(['title' => 'Hello', 'body' => 'World']);
        $article->delete();

        $this->artisan('embedding:vector:clean', ['--force' => true])->assertSuccessful();

        $this->assertTrue($this->hasEmbedding($article->id));
    }

    public function test_missing_payload_follows_the_soft_delete_setting(): void
    {
        $venue = VenueWithPayloadSoftDelete::withoutEmbedding(fn () => VenueWithPayloadSoftDelete::create(['name' => 'Hall', 'province_id' => 34, 'category_id' => 3]));
        $venue->delete();

        config(['embedding.soft_delete' => true]);
        $this->assertSame(1, MissingPayloadResolver::for(VenueWithPayloadSoftDelete::class)->count());

        config(['embedding.soft_delete' => false]);
        $this->assertSame(0, MissingPayloadResolver::for(VenueWithPayloadSoftDelete::class)->count());
    }

    public function test_payload_clean_removes_payload_of_trashed_records_when_embeddings_are_not_kept(): void
    {
        config(['embedding.soft_delete' => true]);
        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Hall', 'province_id' => 34, 'category_id' => 3]);
        $venue->delete();

        config(['embedding.soft_delete' => false]);
        $this->artisan('embedding:payload:clean', ['--force' => true])->assertSuccessful();

        $this->assertFalse(EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayloadSoftDelete::class)
            ->where('embeddable_id', $venue->id)
            ->exists());
    }

    private function trashedArticleWithoutEmbedding(): Article
    {
        $article = Article::withoutEmbedding(fn () => Article::create(['title' => 'Hello', 'body' => 'World']));
        $article->delete();

        return $article;
    }

    private function hasEmbedding(int $id): bool
    {
        return Embedding::query()
            ->where('embeddable_type', Article::class)
            ->where('embeddable_id', $id)
            ->exists();
    }

    /**
     * @return array{records: int, embedded: int}
     */
    private function statusRow(): array
    {
        Artisan::call('embedding:vector:status', ['model' => Article::class, '--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['models'][0];
    }
}
