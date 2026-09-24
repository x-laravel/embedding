<?php

namespace XLaravel\Embedding\Tests\Feature\Payload;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Models\Embeddable as EmbeddablePayload;
use XLaravel\Embedding\Support\MissingPayloadResolver;
use XLaravel\Embedding\Tests\Fixtures\Models\PostWithPayloadOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

class CrossConnectionPayloadTest extends TestCase
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

        Schema::connection('models')->create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_reports_only_records_without_a_payload_row(): void
    {
        $this->seedPosts([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->seedPayload(2);

        $resolver = MissingPayloadResolver::for(PostWithPayloadOnOtherConnection::class);

        $this->assertSame([1, 3], $resolver->lazyModels()->map(fn ($model) => $model->getKey())->all());
        $this->assertSame(2, $resolver->count());
    }

    public function test_sync_backfills_the_missing_rows(): void
    {
        $this->seedPosts([1 => 'A', 2 => 'B']);

        Artisan::call('embedding:payload:sync', [
            'model' => PostWithPayloadOnOtherConnection::class,
            '--sync' => true,
        ]);

        $this->assertSame(2, EmbeddablePayload::query()
            ->where('embeddable_type', PostWithPayloadOnOtherConnection::class)
            ->count());

        $this->assertSame(0, MissingPayloadResolver::for(PostWithPayloadOnOtherConnection::class)->count());
    }

    /**
     * @param  array<int, string>  $titles  id => title
     */
    private function seedPosts(array $titles): void
    {
        PostWithPayloadOnOtherConnection::withoutEmbedding(function () use ($titles) {
            foreach ($titles as $id => $title) {
                PostWithPayloadOnOtherConnection::forceCreate([
                    'id' => $id,
                    'title' => $title,
                    'status' => 'published',
                ]);
            }
        });
    }

    private function seedPayload(int $id): void
    {
        EmbeddablePayload::insert([
            'embeddable_type' => PostWithPayloadOnOtherConnection::class,
            'embeddable_id' => $id,
            'payload' => json_encode(['status' => 'published']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
