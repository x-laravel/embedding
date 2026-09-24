<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('title')]
#[EmbedPayload(['title'])]
class ArticleOnOtherConnection extends Model implements HasEmbeddings
{
    use Embeddable, SoftDeletes;

    protected $connection = 'models';

    protected $table = 'articles';

    protected $fillable = ['title'];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return (string) $this->title;
    }
}
