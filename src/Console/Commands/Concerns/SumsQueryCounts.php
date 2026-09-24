<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait SumsQueryCounts
{
    /**
     * @param  array<int, Builder>  $queries
     */
    private function totalForQueries(array $queries): int
    {
        $sum = 0;

        foreach ($queries as $query) {
            $sum += (clone $query)->count();
        }

        return $sum;
    }
}
