<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Payload;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsPayloadHealthQueries;
use XLaravel\Embedding\Contracts\IdSetBinder;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Models\Embeddable as EmbeddablePayload;
use XLaravel\Embedding\Tests\Fixtures\Models\PostWithPayloadOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

/**
 * The cross-connection branch: the model table cannot be joined, so stale IDs
 * are carried to the payload database. How many of them fit in one query is
 * the binder's call.
 */
class StaleChunkTest extends TestCase
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

    private function seedStalePayloads(int $count): void
    {
        $rows = [];

        for ($id = 1; $id <= $count; $id++) {
            $rows[] = [
                'embeddable_type' => PostWithPayloadOnOtherConnection::class,
                'embeddable_id' => $id,
                'payload' => json_encode(['status' => 'published']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            EmbeddablePayload::insert($chunk);
        }
    }

    public function test_it_splits_the_stale_list_by_the_binder_limit(): void
    {
        $this->seedStalePayloads(2500);

        $queries = $this->healthQueries()->stale();

        $this->assertCount(3, $queries);
        $this->assertSame(2500, collect($queries)->sum(fn ($query) => $query->count()));
    }

    public function test_a_binder_without_a_limit_gets_a_single_query(): void
    {
        $this->seedStalePayloads(2500);

        app(IdSetManager::class)->extend('sqlite', fn () => new class implements IdSetBinder
        {
            public function apply(mixed $query, string $column, array $ids, bool $negate = false): mixed
            {
                return $negate ? $query->whereNotIn($column, $ids) : $query->whereIn($column, $ids);
            }

            public function maxIdsPerQuery(): int
            {
                return 0;
            }
        });

        $queries = $this->healthQueries()->stale();

        $this->assertCount(1, $queries);
        $this->assertSame(2500, $queries[0]->count());
    }

    public function test_rows_whose_model_still_exists_are_left_alone(): void
    {
        $this->seedStalePayloads(3);

        PostWithPayloadOnOtherConnection::withoutEmbedding(fn () => PostWithPayloadOnOtherConnection::forceCreate([
            'id' => 2,
            'title' => 'Still here',
        ]));

        $stale = collect($this->healthQueries()->stale())
            ->flatMap(fn ($query) => $query->pluck('embeddable_id')->all())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 3], $stale);
    }

    public function test_it_returns_nothing_when_every_model_exists(): void
    {
        $this->seedStalePayloads(2);

        PostWithPayloadOnOtherConnection::withoutEmbedding(function () {
            PostWithPayloadOnOtherConnection::forceCreate(['id' => 1, 'title' => 'A']);
            PostWithPayloadOnOtherConnection::forceCreate(['id' => 2, 'title' => 'B']);
        });

        $this->assertSame([], $this->healthQueries()->stale());
    }

    public function test_clean_deletes_every_chunk(): void
    {
        $this->seedStalePayloads(2500);

        PostWithPayloadOnOtherConnection::withoutEmbedding(fn () => PostWithPayloadOnOtherConnection::forceCreate([
            'id' => 1200,
            'title' => 'Still here',
        ]));

        Artisan::call('embedding:payload:clean', ['--force' => true]);

        $this->assertSame([1200], EmbeddablePayload::query()->pluck('embeddable_id')->all());
    }

    private function healthQueries(): object
    {
        return new class
        {
            use BuildsPayloadHealthQueries;

            /** @return array<int, \Illuminate\Database\Eloquent\Builder> */
            public function stale(): array
            {
                return $this->stalePayloadQueries();
            }
        };
    }
}
