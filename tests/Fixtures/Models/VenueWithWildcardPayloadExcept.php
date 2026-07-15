<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('name')]
#[EmbedPayload('*', except: ['description', 'code'])]
class VenueWithWildcardPayloadExcept extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code'];

    public function toEmbeddingText(): string
    {
        return $this->name;
    }
}
