<?php

namespace XLaravel\Embedding\Tests\Feature\Similarity;

use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueMultiSlotWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class PayloadFilterTest extends TestCase
{
    private VenueWithPayload $alpha;
    private VenueWithPayload $beta;
    private VenueWithPayload $gamma;
    private VenueWithPayload $nullProvince;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = VenueWithPayload::create([
            'name' => 'Alpha', 'province_id' => 34, 'category_id' => 3, 'active' => true, 'code' => '34',
        ]);
        $this->beta = VenueWithPayload::create([
            'name' => 'Beta', 'province_id' => 6, 'category_id' => 3, 'active' => false, 'code' => 'B',
        ]);
        $this->gamma = VenueWithPayload::create([
            'name' => 'Gamma', 'province_id' => 34, 'category_id' => 7, 'active' => true,
        ]);
        $this->nullProvince = VenueWithPayload::create([
            'name' => 'NullProvince', 'province_id' => null, 'category_id' => 3, 'active' => true,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function search(?array $filter = null, ?\Closure $where = null, float $threshold = 0.0, int $limit = 10): array
    {
        return VenueWithPayload::similarTo(
            [1.0, 0.0, 0.0],
            limit: $limit,
            threshold: $threshold,
            where: $where,
            filter: $filter,
        )->pluck('id')->sort()->values()->all();
    }

    public function test_no_filter_returns_all(): void
    {
        $this->assertSame(
            [$this->alpha->id, $this->beta->id, $this->gamma->id, $this->nullProvince->id],
            $this->search(),
        );
    }

    public function test_equality_filter(): void
    {
        $this->assertSame(
            [$this->alpha->id, $this->gamma->id],
            $this->search(['province_id' => 34]),
        );
    }

    public function test_in_filter(): void
    {
        $this->assertSame(
            [$this->alpha->id, $this->beta->id, $this->gamma->id],
            $this->search(['province_id' => [34, 6]]),
        );

        $this->assertSame(
            [$this->gamma->id],
            $this->search(['category_id' => [7, 99]]),
        );
    }

    public function test_and_filter(): void
    {
        $this->assertSame(
            [$this->alpha->id],
            $this->search(['province_id' => 34, 'category_id' => 3]),
        );
    }

    public function test_null_payload_value_never_matches(): void
    {
        // nullProvince has province_id = null in its payload — a filter on
        // province_id must never match it, whatever the compared value.
        $this->assertSame([$this->beta->id], $this->search(['province_id' => 6]));
        $this->assertNotContains($this->nullProvince->id, $this->search(['province_id' => [6, 34]]));
    }

    public function test_integer_filter_does_not_match_string_payload_value(): void
    {
        // alpha's code is the string '34'; an integer 34 must not match.
        $this->assertSame([], $this->search(['code' => 34]));
        $this->assertSame([$this->alpha->id], $this->search(['code' => '34']));
    }

    public function test_string_filter_does_not_match_integer_payload_value(): void
    {
        // province_id is stored as integer 34; the string '34' must not match.
        $this->assertSame([], $this->search(['province_id' => '34']));
    }

    public function test_boolean_filter(): void
    {
        $this->assertSame(
            [$this->alpha->id, $this->gamma->id, $this->nullProvince->id],
            $this->search(['active' => true]),
        );
        $this->assertSame([$this->beta->id], $this->search(['active' => false]));
    }

    public function test_record_without_payload_row_never_matches_a_filtered_search(): void
    {
        EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayload::class)
            ->where('embeddable_id', $this->gamma->id)
            ->delete();

        $this->assertSame([$this->alpha->id], $this->search(['province_id' => 34]));

        // Without a filter the record still appears — payload rows only
        // gate filtered searches.
        $this->assertContains($this->gamma->id, $this->search());
    }

    public function test_filter_respects_limit(): void
    {
        $this->assertCount(2, $this->search(['active' => true], limit: 2));
    }

    public function test_filter_combined_with_where(): void
    {
        $this->assertSame(
            [$this->alpha->id],
            $this->search(['province_id' => 34], fn ($q) => $q->where('name', 'Alpha')),
        );
    }

    public function test_filter_combined_with_threshold_and_slot(): void
    {
        $istanbul = VenueMultiSlotWithPayload::create([
            'name' => 'İstanbul Kafe', 'description' => 'Boğaz', 'province_id' => 34, 'category_id' => 3,
        ]);
        VenueMultiSlotWithPayload::create([
            'name' => 'İstanbul Lokanta', 'description' => 'Ada', 'province_id' => 34, 'category_id' => 3,
        ]);
        VenueMultiSlotWithPayload::create([
            'name' => 'Ankara Kafe', 'description' => 'Kale', 'province_id' => 6, 'category_id' => 3,
        ]);

        // Query with istanbul's own stored 'name' vector: its score is 1.0,
        // the other (fake, random 1536-dim) vectors score near 0 — so a 0.9
        // threshold isolates it even though two records pass the filter.
        $queryVector = $istanbul->embedding('name')->first()->vector;

        $result = VenueMultiSlotWithPayload::similarTo(
            $queryVector,
            threshold: 0.9,
            slot: 'name',
            filter: ['province_id' => 34],
        );

        $this->assertSame([$istanbul->id], $result->pluck('id')->all());
    }

    public function test_similar_to_text_forwards_the_filter(): void
    {
        $result = VenueWithPayload::similarToText('kafe', filter: ['category_id' => 7]);

        $this->assertSame([$this->gamma->id], $result->pluck('id')->all());
    }

    public function test_most_similar_forwards_the_filter(): void
    {
        $result = $this->alpha->mostSimilar(filter: ['category_id' => 3]);

        $this->assertSame(
            [$this->beta->id, $this->nullProvince->id],
            $result->pluck('id')->sort()->values()->all(),
        );
    }
}
