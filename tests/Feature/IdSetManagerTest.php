<?php

namespace XLaravel\Embedding\Tests\Feature;

use XLaravel\Embedding\Contracts\IdSetBinder;
use XLaravel\Embedding\IdSet\WhereInBinder;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\TestCase;

class IdSetManagerTest extends TestCase
{
    public function test_it_falls_back_to_the_portable_binder(): void
    {
        $binder = app(IdSetManager::class)->forConnection();

        $this->assertInstanceOf(WhereInBinder::class, $binder);
        $this->assertSame(1000, $binder->maxIdsPerQuery());
    }

    public function test_a_registered_driver_wins_for_its_connection(): void
    {
        $binder = $this->unlimitedBinder();
        app(IdSetManager::class)->extend('sqlite', fn () => $binder);

        $this->assertSame(0, app(IdSetManager::class)->forConnection('sqlite')->maxIdsPerQuery());
    }

    public function test_it_resolves_the_binder_from_a_query(): void
    {
        $unlimited = $this->unlimitedBinder();
        app(IdSetManager::class)->extend('sqlite', fn () => $unlimited);

        $this->assertSame(0, app(IdSetManager::class)->forQuery(Article::query())->maxIdsPerQuery());
    }

    public function test_the_portable_binder_constrains_and_negates(): void
    {
        $keep = Article::create(['title' => 'A', 'body' => 'a']);
        $drop = Article::create(['title' => 'B', 'body' => 'b']);

        $binder = new WhereInBinder;

        $this->assertSame(
            [$keep->id],
            $binder->apply(Article::query(), 'id', [$keep->id])->pluck('id')->all(),
        );

        $this->assertSame(
            [$drop->id],
            $binder->apply(Article::query(), 'id', [$keep->id], negate: true)->pluck('id')->all(),
        );
    }

    private function unlimitedBinder(): IdSetBinder
    {
        return new class implements IdSetBinder
        {
            public function apply(mixed $query, string $column, array $ids, bool $negate = false): mixed
            {
                return $negate ? $query->whereNotIn($column, $ids) : $query->whereIn($column, $ids);
            }

            public function maxIdsPerQuery(): int
            {
                return 0;
            }
        };
    }
}
