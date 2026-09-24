<?php

namespace XLaravel\Embedding\Tests;

use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Embeddings;
use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Embedding\EmbeddingServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            EmbeddingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Minimal AI config — real calls are prevented by Embeddings::fake()
        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'api_key' => 'fake-api-key-for-testing',
        ]);
        $app['config']->set('ai.default_for_embeddings', 'openai');
        $app['config']->set('ai.default_for_reranking', 'cohere');
        $app['config']->set('ai.providers.cohere', [
            'driver' => 'cohere',
            'api_key' => 'fake-cohere-key',
        ]);

        $app['config']->set('embedding.database.connection', 'sqlite');
        $app['config']->set('embedding.queue.connection', 'sync');
    }
}
