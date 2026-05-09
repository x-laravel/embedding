<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn(['title', 'body'])]
class PostWithAttribute extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    public function toEmbeddingText(): string
    {
        return $this->title.' '.$this->body;
    }
}
