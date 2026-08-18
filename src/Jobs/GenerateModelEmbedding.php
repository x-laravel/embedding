<?php

namespace XLaravel\Embedding\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use XLaravel\Embedding\EmbeddingGenerator;

class GenerateModelEmbedding implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Safety-net expiry (seconds) for the uniqueness lock, in case a worker
     * dies before releasing it. Held for the job's full processing duration
     * (success or exhausted retries), not just until it starts.
     */
    public int $uniqueFor;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Model $model, protected string $slot = 'default')
    {
        $this->onConnection(config('embedding.queue.connection', config('queue.default', 'sync')));
        $this->onQueue(config('embedding.queue.generate', 'embedding.generate'));
        $this->uniqueFor = config('embedding.queue.unique_for', 3600);

        // Defer dispatch until the surrounding DB transaction commits, so the
        // job never runs against a row that has not yet been persisted.
        $this->afterCommit = true;
    }

    /**
     * The unique lock key: one in-flight job per model record per slot, so
     * re-triggering generation for a record already queued/processing never
     * dispatches a duplicate, while different records still run in parallel.
     */
    public function uniqueId(): string
    {
        return get_class($this->model).':'.$this->model->getKey().':'.$this->slot;
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
