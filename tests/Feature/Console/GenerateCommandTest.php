<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;
use ReflectionClass;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
use XLaravel\Embedding\Tests\Fixtures\Models\VenueWithPayload;
use XLaravel\Embedding\Tests\TestCase;

class GenerateCommandTest extends TestCase
{
    public function test_generates_missing_embeddings_by_default(): void
    {
        $withEmbedding = Article::create(['title' => 'Has Embedding', 'body' => 'Yes']);
        $withoutEmbedding = Article::withoutEmbedding(function () {
            return Article::create(['title' => 'No Embedding', 'body' => 'Yet']);
        });

        $this->assertTrue($withEmbedding->hasEmbedding());
        $this->assertFalse($withoutEmbedding->hasEmbedding());

        $this->artisan('embedding:generate', ['model' => Article::class])
            ->expectsOutput('Generated embeddings for 1 record(s).')
            ->assertSuccessful();

        $this->assertTrue($withoutEmbedding->fresh()->hasEmbedding());
        $this->assertDatabaseCount('embeddings', 2);
    }

    public function test_skips_existing_embeddings_by_default(): void
    {
        Article::create(['title' => 'First', 'body' => 'Body']);
        Article::create(['title' => 'Second', 'body' => 'Body']);

        $this->assertDatabaseCount('embeddings', 2);

        $this->artisan('embedding:generate', ['model' => Article::class])
            ->expectsOutput('Generated embeddings for 0 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 2);
    }

    public function test_regenerates_all_with_force_option(): void
    {
        $article1 = Article::create(['title' => 'First', 'body' => 'Body']);
        $article2 = Article::create(['title' => 'Second', 'body' => 'Body']);

        $originalVector1 = $article1->fresh()->embedding->vector;

        $this->artisan('embedding:generate', ['model' => Article::class, '--force' => true])
            ->expectsOutput('Generated embeddings for 2 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 2);
    }

    public function test_limit_caps_records_processed_per_slot(): void
    {
        Article::withoutEmbedding(function () {
            for ($i = 0; $i < 5; $i++) {
                Article::create(['title' => "Title {$i}", 'body' => "Body {$i}"]);
            }
        });

        $this->assertDatabaseCount('embeddings', 0);

        $this->artisan('embedding:generate', [
            'model' => Article::class,
            '--limit' => 2,
        ])
            ->expectsOutput('Generated embeddings for 2 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 2);
    }

    public function test_dry_run_reports_counts_without_dispatching(): void
    {
        Article::withoutEmbedding(function () {
            for ($i = 0; $i < 3; $i++) {
                Article::create(['title' => "Title {$i}", 'body' => "Body {$i}"]);
            }
        });

        $this->assertDatabaseCount('embeddings', 0);

        $this->artisan('embedding:generate', [
            'model' => Article::class,
            '--dry-run' => true,
        ])
            ->expectsOutput('Dry-run: would generate embeddings for 3 record(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_prompts_when_multiple_models_discovered_and_aborts_on_no(): void
    {
        $this->app->useAppPath(__DIR__.'/../../Fixtures/Discovery');

        $namespaceProp = (new ReflectionClass($this->app))->getProperty('namespace');
        $namespaceProp->setAccessible(true);
        $namespaceProp->setValue($this->app, 'XLaravel\\Embedding\\Tests\\Fixtures\\Discovery\\');

        $this->artisan('embedding:generate')
            ->expectsOutput('Found 2 models implementing HasEmbeddings:')
            ->expectsConfirmation('Process all of them?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_fails_for_nonexistent_class(): void
    {
        $this->artisan('embedding:generate', ['model' => 'App\Models\NonExistent'])
            ->expectsOutput('Class [App\Models\NonExistent] does not exist.')
            ->assertFailed();
    }

    public function test_fails_for_model_without_has_embeddings(): void
    {
        $this->artisan('embedding:generate', ['model' => \Illuminate\Database\Eloquent\Model::class])
            ->expectsOutput('Class [Illuminate\Database\Eloquent\Model] does not implement HasEmbeddings.')
            ->assertFailed();
    }

    public function test_generates_nothing_when_all_records_have_embeddings(): void
    {
        $this->artisan('embedding:generate', ['model' => Article::class])
            ->expectsOutput('Generated embeddings for 0 record(s).')
            ->assertSuccessful();
    }

    public function test_payload_only_backfills_missing_payload_rows(): void
    {
        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34, 'category_id' => 3])
        );
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'B', 'province_id' => 6])
        );

        $this->assertSame(0, EmbeddableRecord::query()->count());

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
        ])
            ->expectsOutput('Synced payload for 2 record(s).')
            ->assertSuccessful();

        $this->assertSame(2, EmbeddableRecord::query()->count());

        $record = EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayload::class)
            ->where('embeddable_id', $venue->id)
            ->first();
        $this->assertSame(34, $record->payload['province_id']);
        $this->assertSame(3, $record->payload['category_id']);

        // The vector side is never touched.
        $this->assertDatabaseCount('embeddings', 0);
    }

    public function test_payload_only_is_idempotent(): void
    {
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
        ])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
        ])
            ->expectsOutput('Synced payload for 0 record(s).')
            ->assertSuccessful();

        $this->assertSame(1, EmbeddableRecord::query()->count());
    }

    public function test_payload_only_makes_no_ai_call(): void
    {
        VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $aiCalls = 0;

        Embeddings::fake(function () use (&$aiCalls) {
            $aiCalls++;

            return null;
        });

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
        ])->assertSuccessful();

        $this->assertSame(0, $aiCalls);
    }

    public function test_payload_only_dry_run_reports_counts_without_writing(): void
    {
        VenueWithPayload::withoutEmbedding(function () {
            for ($i = 0; $i < 3; $i++) {
                VenueWithPayload::create(['name' => "V{$i}", 'province_id' => $i]);
            }
        });

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
            '--dry-run' => true,
        ])
            ->expectsOutput('Dry-run: would sync payload for 3 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_payload_only_with_force_refreshes_existing_rows(): void
    {
        $venue = VenueWithPayload::create(['name' => 'A', 'province_id' => 34]);

        $tampered = EmbeddableRecord::query()->where('embeddable_id', $venue->id)->first();
        $tampered->payload = ['province_id' => 99];
        $tampered->save();

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
            '--force' => true,
        ])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $record = EmbeddableRecord::query()->where('embeddable_id', $venue->id)->first();
        $this->assertSame(34, $record->payload['province_id']);
    }

    public function test_payload_only_warns_for_model_without_payload_definition(): void
    {
        Article::create(['title' => 'A', 'body' => 'a']);

        $this->artisan('embedding:generate', [
            'model' => Article::class,
            '--payload-only' => true,
        ])
            ->expectsOutput('No embedding payload defined on ['.Article::class.'].')
            ->expectsOutput('Synced payload for 0 record(s).')
            ->assertSuccessful();

        $this->assertSame(0, EmbeddableRecord::query()->count());
    }

    public function test_payload_only_cannot_be_combined_with_slot(): void
    {
        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
            '--slot' => 'default',
        ])
            ->expectsOutput('--payload-only and --slot cannot be combined.')
            ->assertFailed();
    }

    public function test_payload_only_backfills_when_payload_lives_on_a_different_connection(): void
    {
        config([
            'database.connections.secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'embedding.database.connection' => 'secondary',
        ]);

        Schema::connection('secondary')->create('embeddables', function ($table) {
            $table->id();
            $table->morphs('embeddable');
            $table->json('payload');
            $table->timestamps();
            $table->unique(['embeddable_type', 'embeddable_id']);
        });

        $venue = VenueWithPayload::withoutEmbedding(
            fn () => VenueWithPayload::create(['name' => 'A', 'province_id' => 34])
        );

        $this->artisan('embedding:generate', [
            'model' => VenueWithPayload::class,
            '--payload-only' => true,
        ])
            ->expectsOutput('Synced payload for 1 record(s).')
            ->assertSuccessful();

        $record = EmbeddableRecord::query()
            ->where('embeddable_type', VenueWithPayload::class)
            ->where('embeddable_id', $venue->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(34, $record->payload['province_id']);
        $this->assertSame('secondary', $record->getConnection()->getName());
    }

    public function test_generates_missing_embeddings_when_embeddings_live_on_a_different_connection(): void
    {
        config([
            'database.connections.secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'embedding.database.connection' => 'secondary',
        ]);

        Schema::connection('secondary')->create('embeddings', function ($table) {
            $table->id();
            $table->morphs('embeddable');
            $table->string('slot', 64)->default('default');
            $table->json('vector');
            $table->timestamps();
            $table->unique(['embeddable_type', 'embeddable_id', 'slot']);
        });

        $withEmbedding = Article::create(['title' => 'Has Embedding', 'body' => 'Yes']);
        $withoutEmbedding = Article::withoutEmbedding(function () {
            return Article::create(['title' => 'No Embedding', 'body' => 'Yet']);
        });

        $this->assertTrue($withEmbedding->hasEmbedding());
        $this->assertFalse($withoutEmbedding->hasEmbedding());

        $this->artisan('embedding:generate', ['model' => Article::class])
            ->expectsOutput('Generated embeddings for 1 record(s).')
            ->assertSuccessful();

        $this->assertTrue($withoutEmbedding->fresh()->hasEmbedding());
        $this->assertDatabaseCount('embeddings', 2, 'secondary');
    }
}
