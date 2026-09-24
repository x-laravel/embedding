<?php

namespace XLaravel\Embedding\Tests\Feature\Payload;

use InvalidArgumentException;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithInvalidPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueCode;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMethod;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMixed;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithWildcardPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithWildcardPayloadExcept;
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

    public function test_wildcard_model_has_payload_even_without_attributes(): void
    {
        $venue = new VenueWithWildcardPayload();

        $this->assertTrue($venue->hasEmbeddingPayload());
        $this->assertSame([], $venue->embeddingPayloadFields());
    }

    public function test_wildcard_expands_to_attribute_keys_minus_hidden(): void
    {
        $venue = new VenueWithWildcardPayload([
            'name' => 'Kafes',
            'province_id' => 34,
            'secret_token' => 's3cret',
        ]);

        $this->assertSame(['name', 'province_id'], $venue->embeddingPayloadFields());
    }

    public function test_wildcard_excludes_primary_key_and_serializes_dates(): void
    {
        $venue = VenueWithWildcardPayload::create(['name' => 'Kafes', 'province_id' => 34]);

        $payload = $venue->resolveEmbeddingPayload();

        $this->assertArrayNotHasKey('id', $payload);
        $this->assertSame('Kafes', $payload['name']);
        $this->assertSame(34, $payload['province_id']);
        $this->assertIsString($payload['created_at']);
        $this->assertIsString($payload['updated_at']);
    }

    public function test_wildcard_skips_incompatible_values_instead_of_throwing(): void
    {
        $venue = new VenueWithWildcardPayload([
            'name' => 'Kafes',
            'meta' => ['nested' => ['deep' => 1]],
        ]);

        $this->assertSame(['name' => 'Kafes'], $venue->resolveEmbeddingPayload());
    }

    public function test_wildcard_keeps_flat_scalar_arrays(): void
    {
        $venue = new VenueWithWildcardPayload([
            'name' => 'Kafes',
            'meta' => ['a', 'b'],
        ]);

        $this->assertSame(
            ['name' => 'Kafes', 'meta' => ['a', 'b']],
            $venue->resolveEmbeddingPayload(),
        );
    }

    public function test_wildcard_collapses_backed_enums_to_their_value(): void
    {
        $venue = new VenueWithWildcardPayload([
            'name' => 'Kafes',
            'code' => VenueCode::Vip,
        ]);

        $this->assertSame(
            ['name' => 'Kafes', 'code' => 'vip'],
            $venue->resolveEmbeddingPayload(),
        );
    }

    public function test_wildcard_except_removes_columns_from_fields_and_payload(): void
    {
        $venue = new VenueWithWildcardPayloadExcept([
            'name' => 'Kafes',
            'description' => 'Sahil kenarı',
            'code' => 'X1',
            'province_id' => 34,
        ]);

        $this->assertSame(['name', 'province_id'], $venue->embeddingPayloadFields());
        $this->assertSame(
            ['name' => 'Kafes', 'province_id' => 34],
            $venue->resolveEmbeddingPayload(),
        );
    }

    public function test_wildcard_dirty_detection_ignores_hidden_and_excepted_columns(): void
    {
        $venue = new VenueWithWildcardPayload([
            'name' => 'Kafes',
            'province_id' => 34,
            'secret_token' => 's3cret',
        ]);

        $this->assertTrue($venue->payloadFieldsChanged(['province_id']));
        $this->assertFalse($venue->payloadFieldsChanged(['secret_token']));

        $except = new VenueWithWildcardPayloadExcept([
            'name' => 'Kafes',
            'description' => 'Sahil kenarı',
        ]);

        $this->assertTrue($except->payloadFieldsChanged(['name']));
        $this->assertFalse($except->payloadFieldsChanged(['description']));
    }
}
