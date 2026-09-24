<?php

namespace XLaravel\Embedding\Tests\Feature\Console\Payload;

use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class ClearCommandTest extends TestCase
{
    public function test_fails_when_neither_model_nor_all_is_given(): void
    {
        $this->artisan('embedding:payload:clear')
            ->expectsOutput('Provide a model class or use --all.')
            ->assertFailed();
    }

    public function test_fails_when_model_is_combined_with_all(): void
    {
        $this->artisan('embedding:payload:clear', ['model' => VenueWithPayload::class, '--all' => true])
            ->expectsOutput('The [model] argument cannot be combined with --all.')
            ->assertFailed();
    }

    public function test_fails_for_nonexistent_class(): void
    {
        $this->artisan('embedding:payload:clear', ['model' => 'App\\Models\\Missing'])
            ->expectsOutput('Class [App\\Models\\Missing] does not exist.')
            ->assertFailed();
    }

    public function test_clears_payload_rows_for_a_model(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'B', 'province_id' => 6]);

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:clear', ['model' => VenueWithPayload::class, '--force' => true])
            ->expectsOutput('Deleted 2 payload record(s) for ['.VenueWithPayload::class.'].')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_never_touches_vector_embeddings(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $this->assertDatabaseCount('embeddings', 1);

        $this->artisan('embedding:payload:clear', ['model' => VenueWithPayload::class, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());

        // The vector table belongs to embedding:vector:clear.
        $this->assertDatabaseCount('embeddings', 1);
    }

    public function test_clears_entire_table_with_all(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);
        VenueWithPayload::create(['name' => 'B', 'province_id' => 6]);
        Article::create(['title' => 'A', 'body' => 'a']);

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $this->artisan('embedding:payload:clear', ['--all' => true, '--force' => true])
            ->expectsOutput('Deleted 2 payload record(s) from the entire embeddables table.')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
        $this->assertSame(3, Embedding::query()->count());
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $this->artisan('embedding:payload:clear', [
            'model' => VenueWithPayload::class,
            '--dry-run' => true,
        ])
            ->expectsOutput('Dry-run: would delete 1 payload record(s) for ['.VenueWithPayload::class.'].')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_reports_zero_when_nothing_matches(): void
    {
        $this->artisan('embedding:payload:clear', ['model' => VenueWithPayload::class, '--force' => true])
            ->expectsOutput('No payload records to delete for ['.VenueWithPayload::class.'].')
            ->assertSuccessful();
    }

    public function test_aborts_when_user_declines_confirmation(): void
    {
        VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $this->artisan('embedding:payload:clear', ['model' => VenueWithPayload::class])
            ->expectsConfirmation('Delete 1 payload record(s) for ['.VenueWithPayload::class.']?', 'no')
            ->expectsOutput('Aborted.')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }
}
