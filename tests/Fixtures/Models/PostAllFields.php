<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class PostAllFields extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    protected array $embeddable = ['*'];

    public function toEmbeddingText(): string
    {
        return $this->title;
    }
}
