<?php

namespace XLaravel\Embedding\Tests\Feature\Embedding;

use InvalidArgumentException;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMethod;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithWildcardPayload;
use XLaravel\Embedding\Tests\TestCase;

class WherePayloadFilterTest extends TestCase
{
    public function test_scalar_value_matches_by_equality(): void
    {
        $match = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'B', 'province_id' => 6]);

        $this->assertSame(
            [$match->id],
            VenueWithPayload::query()->wherePayloadFilter(['province_id' => 34])->pluck('id')->all(),
        );
    }

    public function test_array_value_matches_any_member(): void
    {
        $first = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        $second = VenueWithPayload::create(['name' => 'B', 'province_id' => 6]);
        VenueWithPayload::create(['name' => 'C', 'province_id' => 35]);

        $this->assertSame(
            [$first->id, $second->id],
            VenueWithPayload::query()->wherePayloadFilter(['province_id' => [34, 6]])->pluck('id')->all(),
        );
    }

    public function test_null_inside_an_array_matches_rows_without_a_value(): void
    {
        $withProvince = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        $withoutProvince = VenueWithPayload::create(['name' => 'B', 'province_id' => null]);
        VenueWithPayload::create(['name' => 'C', 'province_id' => 6]);

        $this->assertSame(
            [$withProvince->id, $withoutProvince->id],
            VenueWithPayload::query()->wherePayloadFilter(['province_id' => [34, null]])->pluck('id')->all(),
        );
    }

    public function test_null_matches_only_rows_without_a_value(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        $empty = VenueWithPayload::create(['name' => 'B', 'province_id' => null]);

        $this->assertSame(
            [$empty->id],
            VenueWithPayload::query()->wherePayloadFilter(['province_id' => null])->pluck('id')->all(),
        );
    }

    public function test_empty_array_matches_nothing(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $this->assertSame(
            [],
            VenueWithPayload::query()->wherePayloadFilter(['province_id' => []])->pluck('id')->all(),
        );
    }

    public function test_multiple_keys_are_combined_with_and(): void
    {
        $both = VenueWithPayload::create(['name' => 'A', 'province_id' => 34, 'category_id' => 2]);
        VenueWithPayload::create(['name' => 'B', 'province_id' => 34, 'category_id' => 9]);
        VenueWithPayload::create(['name' => 'C', 'province_id' => 6, 'category_id' => 2]);

        $this->assertSame(
            [$both->id],
            VenueWithPayload::query()
                ->wherePayloadFilter(['province_id' => 34, 'category_id' => 2])
                ->pluck('id')
                ->all(),
        );
    }

    public function test_it_chains_with_other_constraints(): void
    {
        VenueWithPayload::create(['name' => 'Keep', 'province_id' => 34]);
        $other = VenueWithPayload::create(['name' => 'Drop', 'province_id' => 34]);

        $this->assertSame(
            ['Keep'],
            VenueWithPayload::query()
                ->wherePayloadFilter(['province_id' => 34])
                ->whereKeyNot($other->id)
                ->pluck('name')
                ->all(),
        );
    }

    public function test_it_rejects_a_key_that_is_not_a_payload_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('description');

        VenueWithPayload::query()->wherePayloadFilter(['description' => 'x'])->get();
    }

    public function test_it_rejects_a_key_computed_in_the_payload_method(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VenueWithPayloadMethod::query()->wherePayloadFilter(['computed' => 1])->get();
    }

    public function test_wildcard_payload_skips_the_column_check(): void
    {
        $match = VenueWithWildcardPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithWildcardPayload::create(['name' => 'B', 'province_id' => 6]);

        $this->assertSame(
            [$match->id],
            VenueWithWildcardPayload::query()->wherePayloadFilter(['province_id' => 34])->pluck('id')->all(),
        );
    }

    /**
     * The point of the scope: one filter definition drives both a similarity
     * search and a plain query, so the pool a search sees and the pool a
     * listing shows cannot drift apart.
     */
    public function test_it_selects_the_same_rows_as_a_filtered_similarity_search(): void
    {
        VenueWithPayload::create(['name' => 'Alpha', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'Beta', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'Gamma', 'province_id' => 6]);

        $filter = ['province_id' => 34];
        $vector = VenueWithPayload::first()->embedding()->first()->vector;

        $searched = VenueWithPayload::similarTo($vector, limit: 10, filter: $filter)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $queried = VenueWithPayload::query()
            ->wherePayloadFilter($filter)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($queried, $searched);
    }
}
