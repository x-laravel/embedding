<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

/**
 * Payload rows live with the embeddings, the model does not — so "which
 * records have no payload yet" cannot be joined either.
 */
#[EmbedOn('title')]
#[EmbedPayload(['status'])]
class PostWithPayloadOnOtherConnection extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $connection = 'models';

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->title;
    }
}
