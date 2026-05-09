<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('title', slot: 'title')]
#[EmbedOn('body', slot: 'body')]
#[EmbedOn(['title', 'body'], slot: 'full')]
class PostWithMultiSlotAttribute extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    public function toEmbeddingText(): string|array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'full' => $this->title.' '.$this->body,
        ];
    }
}
