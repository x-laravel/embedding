<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class ArticleKeepEmbedding extends Model implements HasEmbeddings
{
    use Embeddable, SoftDeletes;

    protected $table = 'articles';

    protected $fillable = ['title', 'body'];

    protected array $embeddable = ['title', 'body'];

    protected bool $keepEmbeddingOnSoftDelete = true;

    public function toEmbeddingText(): string
    {
        return $this->title.' '.$this->body;
    }
}
