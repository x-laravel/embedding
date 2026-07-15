<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedPayload(['province_id', 'category_id'])]
class VenueMultiSlotWithPayload extends Model implements HasEmbeddings
{
    use Embeddable;

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code'];

    protected array $embeddable = [
        'name' => ['name'],
        'full' => ['name', 'description'],
    ];

    public function toEmbeddingText(): string|array
    {
        return [
            'name' => $this->name,
            'full' => $this->name.' '.$this->description,
        ];
    }
}
