<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Support\MissingPayloadResolver;
use XLaravel\Embedding\Tests\Fixtures\Models\ArticleOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

class CrossConnectionSoftDeletedSubjectsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.models', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('models')->create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        ArticleOnOtherConnection::withoutEmbedding(function () {
            ArticleOnOtherConnection::forceCreate(['id' => 1, 'title' => 'Live']);
            ArticleOnOtherConnection::forceCreate(['id' => 2, 'title' => 'Trashed', 'deleted_at' => now()]);
        });
    }

    public function test_missing_count_follows_the_soft_delete_setting(): void
    {
        config(['embedding.soft_delete' => true]);
        $this->assertSame(2, ArticleOnOtherConnection::missingEmbeddingCount());

        config(['embedding.soft_delete' => false]);
        $this->assertSame(1, ArticleOnOtherConnection::missingEmbeddingCount());
    }

    public function test_embedded_count_follows_the_soft_delete_setting(): void
    {
        $this->seedEmbedding(1);
        $this->seedEmbedding(2);

        config(['embedding.soft_delete' => true]);
        $this->assertSame(2, ArticleOnOtherConnection::embeddedCount());

        config(['embedding.soft_delete' => false]);
        $this->assertSame(1, ArticleOnOtherConnection::embeddedCount());
    }

    public function test_status_counts_trashed_records_only_when_embeddings_are_kept(): void
    {
        $this->seedEmbedding(1);

        config(['embedding.soft_delete' => true]);
        $kept = $this->statusRow();

        config(['embedding.soft_delete' => false]);
        $dropped = $this->statusRow();

        $this->assertSame([2, 1], [$kept['records'], $kept['embedded']]);
        $this->assertSame([1, 1], [$dropped['records'], $dropped['embedded']]);
    }

    public function test_generate_command_embeds_trashed_records_when_embeddings_are_kept(): void
    {
        config(['embedding.soft_delete' => true]);

        $this->artisan('embedding:vector:generate', ['model' => ArticleOnOtherConnection::class])->assertSuccessful();

        $this->assertTrue($this->hasEmbedding(2));
    }

    public function test_vector_clean_follows_the_soft_delete_setting(): void
    {
        $this->seedEmbedding(2);

        config(['embedding.soft_delete' => true]);
        $this->artisan('embedding:vector:clean', ['--force' => true])->assertSuccessful();
        $this->assertTrue($this->hasEmbedding(2));

        config(['embedding.soft_delete' => false]);
        $this->artisan('embedding:vector:clean', ['--force' => true])->assertSuccessful();
        $this->assertFalse($this->hasEmbedding(2));
    }

    public function test_missing_payload_follows_the_soft_delete_setting(): void
    {
        config(['embedding.soft_delete' => true]);
        $this->assertSame(2, MissingPayloadResolver::for(ArticleOnOtherConnection::class)->count());

        config(['embedding.soft_delete' => false]);
        $this->assertSame(1, MissingPayloadResolver::for(ArticleOnOtherConnection::class)->count());
    }

    public function test_payload_clean_follows_the_soft_delete_setting(): void
    {
        EmbeddableRecord::insert([
            'embeddable_type' => ArticleOnOtherConnection::class,
            'embeddable_id' => 2,
            'payload' => json_encode(['title' => 'Trashed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['embedding.soft_delete' => true]);
        $this->artisan('embedding:payload:clean', ['--force' => true])->assertSuccessful();
        $this->assertTrue($this->hasPayload(2));

        config(['embedding.soft_delete' => false]);
        $this->artisan('embedding:payload:clean', ['--force' => true])->assertSuccessful();
        $this->assertFalse($this->hasPayload(2));
    }

    private function seedEmbedding(int $id): void
    {
        Embedding::insert([
            'embeddable_type' => ArticleOnOtherConnection::class,
            'embeddable_id' => $id,
            'slot' => 'default',
            'vector' => json_encode([0.1, 0.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasEmbedding(int $id): bool
    {
        return Embedding::query()
            ->where('embeddable_type', ArticleOnOtherConnection::class)
            ->where('embeddable_id', $id)
            ->exists();
    }

    private function hasPayload(int $id): bool
    {
        return EmbeddableRecord::query()
            ->where('embeddable_type', ArticleOnOtherConnection::class)
            ->where('embeddable_id', $id)
            ->exists();
    }

    /**
     * @return array{records: int, embedded: int}
     */
    private function statusRow(): array
    {
        Artisan::call('embedding:vector:status', ['model' => ArticleOnOtherConnection::class, '--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['models'][0];
    }
}
