<?php

namespace XLaravel\Embedding\Tests\Feature\Console;

use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use XLaravel\Embedding\Tests\Fixtures\Models\Article;
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
