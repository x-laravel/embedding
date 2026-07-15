<?php

namespace XLaravel\Embedding;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\ServiceProvider;
use XLaravel\Embedding\Console\Commands\CleanCommand;
use XLaravel\Embedding\Console\Commands\ClearCommand;
use XLaravel\Embedding\Console\Commands\Payload\CleanCommand as PayloadCleanCommand;
use XLaravel\Embedding\Console\Commands\Payload\ClearCommand as PayloadClearCommand;
use XLaravel\Embedding\Console\Commands\Payload\StatusCommand as PayloadStatusCommand;
use XLaravel\Embedding\Console\Commands\Payload\SyncCommand as PayloadSyncCommand;
use XLaravel\Embedding\Console\Commands\Vector\CleanCommand as VectorCleanCommand;
use XLaravel\Embedding\Console\Commands\Vector\ClearCommand as VectorClearCommand;
use XLaravel\Embedding\Console\Commands\Vector\GenerateCommand as VectorGenerateCommand;
use XLaravel\Embedding\Console\Commands\Vector\StatusCommand as VectorStatusCommand;
use XLaravel\Embedding\Contracts\EmbeddingClient;
use XLaravel\Embedding\Contracts\PayloadStore;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\SimilarityManager;
use XLaravel\Embedding\Storage\DatabasePayloadStore;
use XLaravel\Embedding\Storage\DatabasePayloadStoreMetrics;
use XLaravel\Embedding\Storage\JsonVectorStore;
use XLaravel\Embedding\Storage\JsonVectorStoreMetrics;

class EmbeddingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/embedding.php', 'embedding');

        $this->app->singleton(SimilarityManager::class);
        $this->app->singleton(Reranker::class);
        $this->app->bind(VectorStore::class, JsonVectorStore::class);
        $this->app->bind(PayloadStore::class, DatabasePayloadStore::class);
        $this->app->bind(VectorStoreMetrics::class, JsonVectorStoreMetrics::class);
        $this->app->bind(PayloadStoreMetrics::class, DatabasePayloadStoreMetrics::class);
        $this->app->bind(EmbeddingClient::class, AiEmbeddingClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerCollectionMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/embedding.php' => config_path('embedding.php'),
            ], 'embedding-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'embedding-migrations');

            $this->commands([
                VectorGenerateCommand::class,
                VectorClearCommand::class,
                VectorCleanCommand::class,
                VectorStatusCommand::class,
                PayloadSyncCommand::class,
                PayloadClearCommand::class,
                PayloadCleanCommand::class,
                PayloadStatusCommand::class,
                ClearCommand::class,
                CleanCommand::class,
            ]);
        }
    }

    protected function registerCollectionMacros(): void
    {
        if (EloquentCollection::hasMacro('rerankWithScores')) {
            return;
        }

        EloquentCollection::macro('rerankWithScores', function (
            string $query,
            int $take = 0,
            float $threshold = 0.0,
            ?string $field = null,
            string $slot = 'default',
        ) {
            /** @var EloquentCollection $this */
            return app(Reranker::class)->rerank($this, $query, $take, $threshold, $field, $slot);
        });
    }
}
