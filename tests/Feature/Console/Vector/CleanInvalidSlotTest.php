<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Vector;

use XLaravel\Embedding\Console\Commands\Concerns\BuildsVectorHealthQueries;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\PostMultiSlot;
use XLaravel\Embedding\Tests\TestCase;

class CleanInvalidSlotTest extends TestCase
{
    /**
     * A record on a slot the model no longer declares, one that is also
     * orphaned, and one that is only orphaned — the three cases the two passes
     * have to divide between them without overlapping.
     */
    private function seedHealthCases(): PostMultiSlot
    {
        $post = PostMultiSlot::create(['title' => 'A', 'body' => 'b']);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => $post->id,
            'slot' => 'ghost',
            'vector' => [0.1, 0.2],
        ]);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => 9999,
            'slot' => 'ghost',
            'vector' => [0.1, 0.2],
        ]);

        Embedding::create([
            'embeddable_type' => PostMultiSlot::class,
            'embeddable_id' => 8888,
            'slot' => 'title',
            'vector' => [0.1, 0.2],
        ]);

        return $post;
    }

    public function test_a_record_that_is_both_orphaned_and_on_a_dropped_slot_is_counted_once(): void
    {
        $this->seedHealthCases();

        $this->artisan('embedding:vector:clean', ['--dry-run' => true])
            ->expectsOutputToContain('Orphan records: 1')
            ->expectsOutputToContain('Invalid slot records: 2')
            ->expectsOutput('Dry-run: would delete 3 embedding(s).')
            ->assertSuccessful();
    }

    public function test_it_deletes_every_stale_record_and_keeps_the_valid_ones(): void
    {
        $post = $this->seedHealthCases();

        $this->artisan('embedding:vector:clean', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Embedding::where('slot', 'ghost')->count());
        $this->assertSame(0, Embedding::whereIn('embeddable_id', [8888, 9999])->count());
        $this->assertSame(3, Embedding::where('embeddable_id', $post->id)->count());
    }

    public function test_orphans_only_leaves_dropped_slots_alone(): void
    {
        $this->seedHealthCases();

        $this->artisan('embedding:vector:clean', ['--orphans-only' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(2, Embedding::where('slot', 'ghost')->count());
        $this->assertSame(0, Embedding::where('embeddable_id', 8888)->count());
    }

    public function test_invalid_slots_only_leaves_orphans_alone(): void
    {
        $this->seedHealthCases();

        $this->artisan('embedding:vector:clean', ['--invalid-slots-only' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Embedding::where('slot', 'ghost')->count());
        $this->assertSame(1, Embedding::where('embeddable_id', 8888)->count());
    }

    /**
     * Whether a slot still exists comes from class metadata, so the query must
     * not look at the model table: across connections that lookup meant
     * shipping every ID to the other database.
     */
    public function test_the_invalid_slot_query_binds_only_the_type_and_the_slots(): void
    {
        $this->seedHealthCases();

        $queries = $this->healthQueries()->invalidSlots();

        $this->assertCount(1, $queries);
        $this->assertSame([PostMultiSlot::class, 'ghost'], $queries[0]->getBindings());
        $this->assertSame(2, $queries[0]->count());
    }

    public function test_the_orphan_query_skips_rows_on_dropped_slots(): void
    {
        $this->seedHealthCases();

        $rows = collect($this->healthQueries()->orphans())
            ->flatMap(fn ($query) => $query->pluck('embeddable_id')->all())
            ->all();

        $this->assertSame([8888], $rows);
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

            /** @return array<int, \Illuminate\Database\Eloquent\Builder> */
            public function invalidSlots(): array
            {
                return $this->invalidSlotQueries();
            }
        };
    }
}
