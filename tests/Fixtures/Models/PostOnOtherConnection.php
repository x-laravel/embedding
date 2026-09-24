<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

/**
 * Lives on a different connection than the embeddings table, so queries that
 * would otherwise join the two have to carry IDs across instead.
 */
#[EmbedOn('title')]
class PostOnOtherConnection extends Model implements HasEmbeddings
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
