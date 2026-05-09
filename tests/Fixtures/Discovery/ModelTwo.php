<?php

namespace XLaravel\Embedding\Tests\Fixtures\Discovery;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class ModelTwo extends Model implements HasEmbeddings
{
    use Embeddable;

    protected array $embeddable = ['title'];

    public function toEmbeddingText(): string
    {
        return (string) $this->title;
    }
}