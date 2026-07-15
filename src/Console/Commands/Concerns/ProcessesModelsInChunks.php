<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

trait ProcessesModelsInChunks
{
    private function processWithProgress(Builder $query, ?Closure $filter, ?int $limit, int $total, Closure $task): int
    {
        $processed = 0;
        $chunk = (int) $this->option('chunk');

        $this->withProgressBar($total, function ($bar) use ($query, $filter, $chunk, $limit, $task, &$processed) {
            $query->chunk($chunk, function ($models) use ($filter, $limit, $task, &$processed, $bar) {
                if ($filter !== null) {
                    $models = $filter($models);
                }

                foreach ($models as $model) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $task($model);
                    $processed++;
                    $bar->advance();
                }
            });
        });

        $this->newLine();

        return $processed;
    }
}
