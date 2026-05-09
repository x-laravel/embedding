<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

class PostMultiSlot extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'posts';

    protected $fillable = ['title', 'body', 'status'];

    protected array $embeddable = [
        'title' => ['title'],
        'body' => ['body'],
        'full' => ['title', 'body'],
    ];

    public function toEmbeddingText(): string|array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'full' => $this->title.' '.$this->body,
        ];
    }
}
