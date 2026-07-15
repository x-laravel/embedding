<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('name')]
class VenueWithPayloadMethod extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code'];

    protected $casts = ['active' => 'boolean'];

    public function toEmbeddingText(): string
    {
        return $this->name;
    }

    public function toEmbeddingPayload(): array
    {
        return [
            'province_id' => $this->province_id,
            'region' => 'region-'.$this->province_id,
        ];
    }
}
