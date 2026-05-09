<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class PostNoEmbedding extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    // $embeddable = [] by default — never embeds automatically

    public function toEmbeddingText(): string
    {
        return $this->title;
    }
}
