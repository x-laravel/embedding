<?php

namespace XLaravel\Embedding\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use XLaravel\Embedding\EmbeddingGenerator;

class GenerateModelEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Model $model, protected string $slot = 'default')
    {
        $this->onConnection(config('embedding.queue.connection', config('queue.default', 'sync')));
        $this->onQueue(config('embedding.queue.name', 'embedding'));

        // Defer dispatch until the surrounding DB transaction commits, so the
        // job never runs against a row that has not yet been persisted.
        $this->afterCommit = true;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'slot:'.$this->slot,
            get_class($this->model).':'.$this->model->getKey(),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddingGenerator $generator): void
    {
        $generator->generate($this->model, $this->slot);
    }
}
