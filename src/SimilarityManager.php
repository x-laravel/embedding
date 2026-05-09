<?php

namespace XLaravel\Embedding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Manager;
use XLaravel\Embedding\Similarity\PhpDriver;

class SimilarityManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $configured = $this->config->get('embedding.similarity.driver', 'auto');

        if ($configured !== 'auto') {
            return $configured;
        }

        $driver = DB::connection(config('embedding.database.connection'))->getDriverName();

        // Use the DB-specific driver if registered for this connection, otherwise fall back to php.
        return isset($this->customCreators[$driver]) ? $driver : 'php';
    }

    protected function createPhpDriver(): PhpDriver
    {
        return new PhpDriver();
    }
}
