<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Vector;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsVectorHealthQueries;
use XLaravel\Embedding\Contracts\IdSetBinder;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\PostOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

/**
 * The cross-connection branch: the model table cannot be joined, so orphan IDs
 * are carried to the embedding database. How many of them fit in one query is
 * the binder's call.
 */
class OrphanChunkTest extends TestCase
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
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    private function seedOrphans(int $count): void
    {
        $rows = [];

        for ($id = 1; $id <= $count; $id++) {
            $rows[] = [
                'embeddable_type' => PostOnOtherConnection::class,
                'embeddable_id' => $id,
                'slot' => 'default',
                'vector' => json_encode([0.1, 0.2]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Embedding::insert($chunk);
        }
    }

    public function test_it_splits_the_orphan_list_by_the_binder_limit(): void
    {
        $this->seedOrphans(2500);

        $queries = $this->healthQueries()->orphans();

        $this->assertCount(3, $queries);
        $this->assertSame(2500, collect($queries)->sum(fn ($query) => $query->count()));
    }

    public function test_a_binder_without_a_limit_gets_a_single_query(): void
    {
        $this->seedOrphans(2500);

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

        $queries = $this->healthQueries()->orphans();

        $this->assertCount(1, $queries);
        $this->assertSame(2500, $queries[0]->count());
    }

    public function test_rows_whose_model_still_exists_are_left_alone(): void
    {
        $this->seedOrphans(3);

        PostOnOtherConnection::withoutEmbedding(fn () => PostOnOtherConnection::forceCreate([
            'id' => 2,
            'title' => 'Still here',
        ]));

        $orphans = collect($this->healthQueries()->orphans())
            ->flatMap(fn ($query) => $query->pluck('embeddable_id')->all())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 3], $orphans);
    }

    public function test_it_returns_nothing_when_every_model_exists(): void
    {
        $this->seedOrphans(2);

        PostOnOtherConnection::withoutEmbedding(function () {
            PostOnOtherConnection::forceCreate(['id' => 1, 'title' => 'A']);
            PostOnOtherConnection::forceCreate(['id' => 2, 'title' => 'B']);
        });

        $this->assertSame([], $this->healthQueries()->orphans());
    }

    private function healthQueries(): object
    {
        return new class
        {
            use BuildsVectorHealthQueries;

            /** @return array<int, \Illuminate\Database\Eloquent\Builder> */
            public function orphans(): array
            {
                return $this->orphanQueries();
            }
        };
    }
}
