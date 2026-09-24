<?php

namespace XLaravel\Embedding\Tests\Feature\Payload;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadSoftDelete;
use XLaravel\Embedding\Tests\TestCase;

class PayloadSoftDeleteTest extends TestCase
{
    private function payloadRecord(Model $model): ?EmbeddableRecord
    {
        return EmbeddableRecord::query()
            ->where('embeddable_type', get_class($model))
            ->where('embeddable_id', $model->getKey())
            ->first();
    }

    public function test_payload_row_is_deleted_on_soft_delete_by_default(): void
    {
        config(['embedding.soft_delete' => false]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Kafes', 'province_id' => 34]);
        $this->assertNotNull($this->payloadRecord($venue));

        $venue->delete();

        $this->assertNull($this->payloadRecord($venue));
    }

    public function test_payload_row_is_rebuilt_on_restore_by_default(): void
    {
        config(['embedding.soft_delete' => false]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Kafes', 'province_id' => 34]);
        $venue->delete();
        $this->assertNull($this->payloadRecord($venue));

        $venue->restore();

        $this->assertSame(
            ['province_id' => 34, 'category_id' => null],
            $this->payloadRecord($venue)->payload,
        );
        $this->assertSame(1, $venue->embeddings()->count());
    }

    public function test_payload_row_is_kept_on_soft_delete_when_keep_is_enabled(): void
    {
        config(['embedding.soft_delete' => true]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Kafes', 'province_id' => 34]);

        $venue->delete();

        $this->assertSame(34, $this->payloadRecord($venue)->payload['province_id']);
    }

    public function test_restore_leaves_kept_payload_row_untouched(): void
    {
        config(['embedding.soft_delete' => true]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Kafes', 'province_id' => 34]);
        $originalUpdatedAt = $this->payloadRecord($venue)->updated_at;

        $venue->delete();
        $venue->restore();

        $record = $this->payloadRecord($venue);
        $this->assertSame(34, $record->payload['province_id']);
        $this->assertEquals($originalUpdatedAt, $record->updated_at);
    }

    public function test_force_delete_removes_payload_row_regardless_of_config(): void
    {
        config(['embedding.soft_delete' => true]);

        $venue = VenueWithPayloadSoftDelete::create(['name' => 'Kafes', 'province_id' => 34]);
        $this->assertNotNull($this->payloadRecord($venue));

        $venue->forceDelete();

        $this->assertNull($this->payloadRecord($venue));
    }
}
