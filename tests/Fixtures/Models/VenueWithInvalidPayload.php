<?php

namespace XLaravel\Embedding\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('name')]
class VenueWithInvalidPayload extends Model implements HasEmbeddings
{
    use Embeddable;

    /**
     * Test-controlled payload; set per test, reset between tests.
     *
     * @var array<string, mixed>
     */
    public static array $payload = [];

    protected $table = 'venues';

    protected $fillable = ['name', 'description', 'province_id', 'category_id', 'active', 'code'];

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->name;
    }

    public function toEmbeddingPayload(): array
    {
        return static::$payload;
    }
}
