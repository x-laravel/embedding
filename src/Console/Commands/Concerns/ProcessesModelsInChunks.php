<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

trait ProcessesModelsInChunks
{
    /**
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    private function processIterableWithProgress(iterable $models, ?int $limit, int $total, Closure $task): int
    {
        $processed = 0;

        $this->withProgressBar($total, function ($bar) use ($models, $limit, $task, &$processed) {
            foreach ($models as $model) {
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                $task($model);
                $processed++;
                $bar->advance();
            }
        });

        $this->newLine();

        return $processed;
    }

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
