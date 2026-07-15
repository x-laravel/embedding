<?php

namespace XLaravel\Embedding\Tests\Feature\Payload;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Jobs\SyncModelPayload;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueMultiSlotWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayloadMethod;
use XLaravel\Embedding\Tests\TestCase;

class PayloadWritePathTest extends TestCase
{
    private function payloadRecord(Model $model): ?EmbeddableRecord
    {
        return EmbeddableRecord::query()
            ->where('embeddable_type', get_class($model))
            ->where('embeddable_id', $model->getKey())
            ->first();
    }

    public function test_insert_creates_all_slot_embeddings_and_a_payload_row(): void
    {
        $venue = VenueMultiSlotWithPayload::create([
            'name' => 'Kafes',
            'description' => 'Sahil kenarı',
            'province_id' => 34,
            'category_id' => 3,
        ]);

        $this->assertSame(2, $venue->embeddings()->count());

        $record = $this->payloadRecord($venue);
        $this->assertNotNull($record);
        $this->assertSame(['province_id' => 34, 'category_id' => 3], $record->payload);
    }

    public function test_insert_without_payload_definition_creates_no_row(): void
    {
        Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_payload_only_change_dispatches_sync_job_but_no_vector_job(): void
    {
        $venue = VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34]);

        Bus::fake([SyncModelPayload::class, GenerateModelEmbedding::class]);

        $venue->update(['province_id' => 42]);

        Bus::assertDispatched(
            SyncModelPayload::class,
            fn (SyncModelPayload $job) => $job->queue === 'embedding.sync-payload',
        );
        Bus::assertNotDispatched(GenerateModelEmbedding::class);
    }

    public function test_payload_only_change_makes_no_ai_call_and_leaves_vector_untouched(): void
    {
        $venue = VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34]);
        $vectorBefore = $venue->embedding()->first()->vector;

        // Embeddings::assertNothingGenerated() cannot be used here: the Ai
        // manager keeps every recorded generation for the whole test — the
        // one made during create() included. A counting fake isolates the
        // calls made by the update alone.
        $aiCalls = 0;

        Embeddings::fake(function () use (&$aiCalls) {
            $aiCalls++;

            return null;
        });

        $venue->update(['province_id' => 42]);

        $this->assertSame(0, $aiCalls);
        $this->assertEquals($vectorBefore, $venue->embedding()->first()->vector);
        $this->assertSame(42, $this->payloadRecord($venue)->payload['province_id']);
    }

    public function test_embeddable_and_payload_field_in_same_save_update_both_sides(): void
    {
        $venue = VenueWithPayload::create(['name' => 'Eski Ad', 'province_id' => 34]);
        $vectorBefore = $venue->embedding()->first()->vector;

        $venue->update(['name' => 'Yeni Ad', 'province_id' => 42]);

        // Regression for the v1 single-table design: the payload must be
        // current in the same save that re-embeds the vector.
        $this->assertNotEquals($vectorBefore, $venue->embedding()->first()->vector);
        $this->assertSame(42, $this->payloadRecord($venue)->payload['province_id']);
    }

    public function test_embed_only_change_does_not_dispatch_payload_job(): void
    {
        $venue = VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34]);

        Bus::fake([SyncModelPayload::class, GenerateModelEmbedding::class]);

        $venue->update(['name' => 'Yeni Ad']);

        Bus::assertDispatched(GenerateModelEmbedding::class);
        Bus::assertNotDispatched(SyncModelPayload::class);
    }

    public function test_multi_slot_model_keeps_a_single_payload_row(): void
    {
        $venue = VenueMultiSlotWithPayload::create([
            'name' => 'Kafes',
            'description' => 'Sahil kenarı',
            'province_id' => 34,
            'category_id' => 3,
        ]);

        $rowsFor = fn () => EmbeddableRecord::query()
            ->where('embeddable_type', VenueMultiSlotWithPayload::class)
            ->where('embeddable_id', $venue->getKey())
            ->count();

        $this->assertSame(1, $rowsFor());

        $venue->update(['name' => 'Yeni Ad']);
        $this->assertSame(1, $rowsFor());

        $venue->update(['province_id' => 42]);
        $this->assertSame(1, $rowsFor());
        $this->assertSame(42, $this->payloadRecord($venue)->payload['province_id']);
    }

    public function test_method_only_payload_is_written_on_insert(): void
    {
        $venue = VenueWithPayloadMethod::create(['name' => 'Kafes', 'province_id' => 6]);

        $this->assertSame(
            ['province_id' => 6, 'region' => 'region-6'],
            $this->payloadRecord($venue)->payload,
        );
    }

    public function test_method_only_payload_is_not_auto_synced_on_column_change(): void
    {
        $venue = VenueWithPayloadMethod::create(['name' => 'Kafes', 'province_id' => 6]);

        // toEmbeddingPayload() values come from no declared column, so dirty
        // detection cannot see the change — documented behaviour.
        $venue->update(['province_id' => 7]);
        $this->assertSame('region-6', $this->payloadRecord($venue)->payload['region']);

        $venue->syncEmbeddingPayload();
        $this->assertSame(
            ['province_id' => 7, 'region' => 'region-7'],
            $this->payloadRecord($venue)->payload,
        );
    }

    public function test_sync_helper_is_a_noop_without_payload_definition(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $post->syncEmbeddingPayload();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_without_embedding_suppresses_payload_write(): void
    {
        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34])
        );

        $this->assertSame(0, $venue->embeddings()->count());
        $this->assertNull($this->payloadRecord($venue));
    }

    public function test_sync_helper_works_even_when_embedding_is_disabled(): void
    {
        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34])
        );

        VenueWithPayload::disableEmbedding();

        try {
            $venue->syncEmbeddingPayload();
        } finally {
            VenueWithPayload::enableEmbedding();
        }

        $this->assertSame(34, $this->payloadRecord($venue)->payload['province_id']);
    }

    public function test_hard_delete_removes_the_payload_row(): void
    {
        $venue = VenueWithPayload::create(['name' => 'Kafes', 'province_id' => 34]);
        $this->assertNotNull($this->payloadRecord($venue));

        $venue->delete();

        $this->assertNull($this->payloadRecord($venue));
        $this->assertSame(0, $venue->embeddings()->count());
    }
}
