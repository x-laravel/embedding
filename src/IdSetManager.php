<?php

namespace XLaravel\Embedding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Manager;
use XLaravel\Embedding\Contracts\IdSetBinder;
use XLaravel\Embedding\IdSet\WhereInBinder;

/**
 * Resolves the IdSetBinder for a connection. Driver packages register their own
 * with extend(); connections without one get the portable fallback.
 */
class IdSetManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'whereIn';
    }

    public function forConnection(?string $connection = null): IdSetBinder
    {
        $driver = DB::connection($connection)->getDriverName();

        return $this->driver(isset($this->customCreators[$driver]) ? $driver : 'whereIn');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     */
    public function forQuery(mixed $query): IdSetBinder
    {
        return $this->forConnection($query->getConnection()->getName());
    }

    protected function createWhereInDriver(): WhereInBinder
    {
        return new WhereInBinder();
    }
}
