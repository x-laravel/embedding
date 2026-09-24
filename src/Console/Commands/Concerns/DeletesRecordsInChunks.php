<?php

namespace XLaravel\Embedding\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait DeletesRecordsInChunks
{
    use SumsQueryCounts;

    /**
     * @param  array<int, Builder>  $queries
     */
    private function deleteWithProgress(array $queries, int $total): void
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->withProgressBar($total, function ($bar) use ($queries, $chunkSize) {
            foreach ($queries as $query) {
                // Queries target either the embeddings or the embeddables
                // model — derive the key/model from the query itself. Only
                // the key is selected so vector/payload JSON never loads.
                $model = $query->getModel();
                $key = $model->getKeyName();

                $query->select([$key])->chunkById($chunkSize, function ($rows) use ($bar, $model, $key) {
                    $model->newQuery()
                        ->whereIn($key, $rows->modelKeys())
                        ->delete();
                    $bar->advance($rows->count());
                }, $key);
            }
        });

        $this->newLine();
    }
}
