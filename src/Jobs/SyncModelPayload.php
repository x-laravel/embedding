<?php

namespace XLaravel\Embedding\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use XLaravel\Embedding\Contracts\PayloadStore;

/**
 * The single writer of payload records. Vector jobs never touch the
 * payload, so no per-slot race over the shared row can ever occur.
 */
class SyncModelPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Model $model)
    {
        $this->onConnection(config('embedding.queue.connection', config('queue.default', 'sync')));

        // Dedicated queue: a fast DB upsert must not wait behind slow
        // AI-bound vector jobs (head-of-line blocking).
        $this->onQueue(config('embedding.queue.sync_payload', 'embedding.sync-payload'));

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
            'embedding',
            'payload',
            get_class($this->model).':'.$this->model->getKey(),
        ];
    }

    /**
     * Execute the job. The payload is resolved at run time, not at
     * dispatch time, so the freshest attribute values win.
     */
    public function handle(PayloadStore $store): void
    {
        $store->upsert($this->model, $this->model->resolveEmbeddingPayload());
    }
}
