<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | This option specifies the Eloquent model used to store embedding records.
    | You may swap it with your own model if you need to customize the table
    | structure, add relationships, or override casting behaviour.
    |
    */

    'model' => \XLaravel\Embedding\Models\Embedding::class,

    /*
    |--------------------------------------------------------------------------
    | Vector Dimensions
    |--------------------------------------------------------------------------
    |
    | The number of dimensions in the embedding vector. This must match the
    | output size of the AI model generating the embeddings. On PostgreSQL
    | with pgvector, this value is used to define the vector column type.
    | Common values: 1536 (OpenAI text-embedding-3-small), 3072 (large).
    |
    */

    'dimensions' => env('EMBEDDING_DIMENSIONS', 1536),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Here you may configure the database connection and table name used to
    | store embeddings. By default the application's primary connection and
    | an "embeddings" table are used, but you may point to a dedicated
    | database (e.g. one with pgvector support) when needed.
    |
    */

    'database' => [
        'connection' => env('EMBEDDINGS_DATABASE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
        'table' => env('EMBEDDINGS_DB_TABLE', 'embeddings'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Embedding generation is dispatched as a queued job. You may configure
    | which connection and queue name to use. Set the connection to "sync"
    | to generate embeddings inline without a queue worker.
    |
    */

    'queue' => [
        'connection' => env('QUEUE_CONNECTION', 'sync'),
        'name' => env('EMBEDDING_QUEUE', 'embedding'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Similarity Search
    |--------------------------------------------------------------------------
    |
    | This option controls the driver used for similarity search. The default
    | "auto" value selects the best driver based on the database connection:
    | "pgsql" uses pgvector's native <=> operator; all other connections
    | fall back to the "php" driver which computes cosine similarity in PHP.
    | Custom drivers can be registered via SimilarityManager::extend().
    |
    */

    'similarity' => [
        'driver' => env('EMBEDDING_SIMILARITY_DRIVER', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | When enabled, soft-deleting a model will retain its embedding record so
    | that restoring the model does not incur regeneration costs. Force deletes
    | always remove the embedding regardless of this setting.
    |
    */

    'soft_delete' => false,

];
