<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('name')]
#[EmbedPayload('*')]
class VenueWithWildcardPayload extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code', 'secret_token', 'meta'];

    protected $hidden = ['secret_token'];

    protected $casts = ['active' => 'boolean', 'meta' => 'array', 'code' => VenueCode::class];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->name;
    }
}
