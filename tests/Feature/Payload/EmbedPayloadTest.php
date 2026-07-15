<?php

namespace XLaravel\Embedding\Tests\Feature\Payload;

use InvalidArgumentException;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithInvalidPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMethod;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMixed;
use XLaravel\Embedding\Tests\TestCase;

class EmbedPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        VenueWithInvalidPayload::$payload = [];
    }

    public function test_attribute_declares_payload_fields(): void
    {
        $this->assertSame(
            ['province_id', 'category_id', 'active', 'code'],
            (new VenueWithPayload())->embeddingPayloadFields(),
        );
    }

    public function test_method_only_model_has_no_payload_fields_but_has_payload(): void
    {
        $venue = new VenueWithPayloadMethod();

        $this->assertSame([], $venue->embeddingPayloadFields());
        $this->assertTrue($venue->hasEmbeddingPayload());
    }

    public function test_model_without_definition_has_no_payload(): void
    {
        $this->assertFalse((new Post())->hasEmbeddingPayload());
    }

    public function test_attribute_fields_resolve_through_get_attribute(): void
    {
        $venue = new VenueWithPayload([
            'province_id' => 34,
            'category_id' => 3,
            'active' => true,
        ]);

        $this->assertSame([
            'province_id' => 34,
            'category_id' => 3,
            'active' => true,
            'code' => null,
        ], $venue->resolveEmbeddingPayload());
    }

    public function test_method_only_payload_is_resolved(): void
    {
        $venue = new VenueWithPayloadMethod(['province_id' => 6]);

        $this->assertSame([
            'province_id' => 6,
            'region' => 'region-6',
        ], $venue->resolveEmbeddingPayload());
    }

    public function test_method_wins_over_attribute_on_key_collision(): void
    {
        $venue = new VenueWithPayloadMixed([
            'province_id' => 34,
            'category_id' => 3,
        ]);

        $this->assertSame([
            'province_id' => 34,
            'category_id' => 999,
            'computed' => 'extra',
        ], $venue->resolveEmbeddingPayload());
    }

    public function test_object_payload_value_throws(): void
    {
        VenueWithInvalidPayload::$payload = ['meta' => new \stdClass()];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[meta]');

        (new VenueWithInvalidPayload(['name' => 'V']))->resolveEmbeddingPayload();
    }

    public function test_nested_array_payload_value_throws(): void
    {
        VenueWithInvalidPayload::$payload = ['meta' => ['inner' => ['deep' => 1]]];

        $this->expectException(InvalidArgumentException::class);

        (new VenueWithInvalidPayload(['name' => 'V']))->resolveEmbeddingPayload();
    }

    public function test_flat_scalar_array_payload_value_is_valid(): void
    {
        VenueWithInvalidPayload::$payload = ['tags' => [1, 'two', true, null]];

        $this->assertSame(
            ['tags' => [1, 'two', true, null]],
            (new VenueWithInvalidPayload(['name' => 'V']))->resolveEmbeddingPayload(),
        );
    }

    public function test_payload_fields_changed_detects_attribute_columns(): void
    {
        $venue = new VenueWithPayload();

        $this->assertTrue($venue->payloadFieldsChanged(['province_id', 'updated_at']));
        $this->assertFalse($venue->payloadFieldsChanged(['description', 'updated_at']));
        $this->assertFalse($venue->payloadFieldsChanged([]));
    }

    public function test_method_computed_values_are_invisible_to_dirty_detection(): void
    {
        $this->assertFalse((new VenueWithPayloadMethod())->payloadFieldsChanged(['province_id']));
    }

    public function test_payload_fields_cache_can_be_flushed_and_recomputed(): void
    {
        $before = (new VenueWithPayload())->embeddingPayloadFields();

        VenueWithPayload::flushEmbeddingPayloadFieldsCache();

        $this->assertSame($before, (new VenueWithPayload())->embeddingPayloadFields());
    }
}
