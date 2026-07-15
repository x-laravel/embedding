<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('name')]
#[EmbedPayload(['province_id', 'category_id'])]
class VenueWithPayloadSoftDelete extends Model implements HasEmbeddings
{
    use Embeddable, SoftDeletes;

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code'];

    public function toEmbeddingText(): string
    {
        return $this->name;
    }
}
