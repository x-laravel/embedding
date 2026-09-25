<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsPayloadHealthQueries;
use XLaravel\Embedding\Console\Commands\Concerns\BuildsVectorHealthQueries;
use XLaravel\Embedding\Models\Embeddable as EmbeddablePayload;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Support\KeyWindows;
use XLaravel\Embedding\Tests\Fixtures\Models\PostOnOtherConnection;
use XLaravel\Embedding\Tests\Fixtures\Models\PostWithPayloadOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

/**
 * Counts and health checks across connections walk the key sets in windows of
 * KeyWindows::SIZE; one key past a full window must still be seen.
 */
class CrossConnectionWindowsTest extends TestCase
{
    private const TOTAL = KeyWindows::SIZE + 1;

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

    public function test_embedded_count_sees_both_sides_of_a_window(): void
    {
        $this->seedPosts(range(1, self::TOTAL));
        $this->seedEmbeddings([1, self::TOTAL]);

        $this->assertSame(2, PostOnOtherConnection::embeddedCount());
    }

    public function test_payload_status_counts_coverage_across_windows(): void
    {
        $this->seedPosts(range(1, self::TOTAL));
        $this->seedPayloads(range(2, self::TOTAL));

        Artisan::call('embedding:payload:status', [
            'model' => PostWithPayloadOnOtherConnection::class,
            '--json' => true,
        ]);

        $coverage = json_decode(Artisan::output(), true)['models'][0];

        $this->assertSame(self::TOTAL, $coverage['records']);
        $this->assertSame(KeyWindows::SIZE, $coverage['with_payload']);
    }

    public function test_stale_payloads_are_found_past_a_full_window(): void
    {
        $this->seedPosts([1]);
        $this->seedPayloads(range(1, self::TOTAL));

        $stale = collect($this->payloadHealth()->stale())
            ->flatMap(fn ($query) => $query->pluck('embeddable_id')->all());

        $this->assertCount(KeyWindows::SIZE, $stale);
        $this->assertNotContains(1, $stale);
        $this->assertContains(self::TOTAL, $stale);
    }

    public function test_orphans_are_found_past_a_full_window(): void
    {
        $this->seedPosts([1]);
        $this->seedEmbeddings(range(1, self::TOTAL));

        $orphans = collect($this->vectorHealth()->orphans())
            ->flatMap(fn ($query) => $query->pluck('embeddable_id')->all());

        $this->assertCount(KeyWindows::SIZE, $orphans);
        $this->assertNotContains(1, $orphans);
        $this->assertContains(self::TOTAL, $orphans);
    }

    public function test_a_repeated_key_is_read_once(): void
    {
        $this->seedEmbeddings(range(1, self::TOTAL));
        $this->seedEmbeddings(range(1, self::TOTAL), 'title');

        $ids = [];

        foreach (KeyWindows::missing(Embedding::query(), fn () => [], key: 'embeddable_id') as $window) {
            array_push($ids, ...$window);
        }

        $this->assertSame(range(1, self::TOTAL), $ids);
    }

    /**
     * @param  list<int>  $ids
     */
    private function seedPosts(array $ids): void
    {
        $rows = array_map(fn (int $id) => ['id' => $id, 'title' => "Post {$id}"], $ids);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::connection('models')->table('posts')->insert($chunk);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function seedEmbeddings(array $ids, string $slot = 'default'): void
    {
        $rows = array_map(fn (int $id) => [
            'embeddable_type' => PostOnOtherConnection::class,
            'embeddable_id' => $id,
            'slot' => $slot,
            'vector' => json_encode([0.1, 0.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids);

        foreach (array_chunk($rows, 500) as $chunk) {
            Embedding::insert($chunk);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function seedPayloads(array $ids): void
    {
        $rows = array_map(fn (int $id) => [
            'embeddable_type' => PostWithPayloadOnOtherConnection::class,
            'embeddable_id' => $id,
            'payload' => json_encode(['status' => 'published']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids);

        foreach (array_chunk($rows, 500) as $chunk) {
            EmbeddablePayload::insert($chunk);
        }
    }

    private function payloadHealth(): object
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

    private function vectorHealth(): object
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
