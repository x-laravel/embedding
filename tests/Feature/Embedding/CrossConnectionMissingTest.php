<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Support\KeyWindows;
use XLaravel\Embedding\Support\MissingSlotResolver;
use XLaravel\Embedding\Tests\Fixtures\Models\PostOnOtherConnection;
use XLaravel\Embedding\Tests\TestCase;

/**
 * The model and the embeddings sit on different connections, so the two key
 * sets are compared in PHP, one window at a time.
 */
class CrossConnectionMissingTest extends TestCase
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

    public function test_it_reports_only_records_without_an_embedding(): void
    {
        $this->seedPosts([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->seedEmbedding(2);

        $this->assertSame([1, 3], $this->resolver()->ids());
        $this->assertSame(2, PostOnOtherConnection::missingEmbeddingCount('default'));
    }

    public function test_text_that_normalizes_to_blank_is_never_missing(): void
    {
        $this->seedPosts([1 => 'A', 2 => '   ']);

        $this->assertSame([1], $this->resolver()->ids());

        // The blank one is still a candidate — only resolving its text rules
        // it out, which is why the count and the candidate total differ.
        $this->assertSame(2, $this->resolver()->baseCount());
        $this->assertSame(1, $this->resolver()->count());
    }

    public function test_it_reports_everything_when_nothing_is_embedded(): void
    {
        $this->seedPosts([1 => 'A', 2 => 'B']);

        $this->assertSame([1, 2], $this->resolver()->ids());
    }

    public function test_it_keeps_no_records_when_all_are_embedded(): void
    {
        $this->seedPosts([1 => 'A', 2 => 'B']);
        $this->seedEmbedding(1);
        $this->seedEmbedding(2);

        $this->assertSame([], $this->resolver()->ids());
        $this->assertSame(0, $this->resolver()->count());
    }

    public function test_an_embedding_for_another_slot_does_not_count(): void
    {
        $this->seedPosts([1 => 'A']);
        $this->seedEmbedding(1, 'title');

        $this->assertSame([1], $this->resolver()->ids());
    }

    public function test_it_drops_no_keys_at_a_window_boundary(): void
    {
        $total = KeyWindows::SIZE + 1;
        $rows = [];

        for ($id = 1; $id <= $total; $id++) {
            $rows[] = ['id' => $id, 'title' => "Post {$id}"];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::connection('models')->table('posts')->insert($chunk);
        }

        $this->seedEmbedding($total);

        $ids = $this->resolver()->ids();

        $this->assertCount(KeyWindows::SIZE, $ids);
        $this->assertNotContains($total, $ids);
        $this->assertSame(1, $ids[0]);
    }

    private function resolver(string $slot = 'default'): MissingSlotResolver
    {
        return MissingSlotResolver::for(PostOnOtherConnection::class, $slot);
    }

    /**
     * @param  array<int, string>  $titles  id => title
     */
    private function seedPosts(array $titles): void
    {
        PostOnOtherConnection::withoutEmbedding(function () use ($titles) {
            foreach ($titles as $id => $title) {
                PostOnOtherConnection::forceCreate(['id' => $id, 'title' => $title]);
            }
        });
    }

    private function seedEmbedding(int $id, string $slot = 'default'): void
    {
        Embedding::insert([
            'embeddable_type' => PostOnOtherConnection::class,
            'embeddable_id' => $id,
            'slot' => $slot,
            'vector' => json_encode([0.1, 0.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
