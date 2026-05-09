<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $fillable = ['title', 'body', 'status'];

    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(): string
    {
        return $this->title.' '.$this->body;
    }
}
