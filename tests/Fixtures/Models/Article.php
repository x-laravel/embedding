<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class Article extends Model implements HasEmbeddings
{
    use Embeddable, SoftDeletes;

    protected $fillable = ['title', 'body'];

    protected array $embeddable = ['title', 'body'];

    public function toEmbeddingText(): string
    {
        return $this->title.' '.$this->body;
    }
}
