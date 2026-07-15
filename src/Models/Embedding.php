<?php

namespace XLaravel\Embedding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Embedding extends Model
{
    protected $fillable = ['embeddable_type', 'embeddable_id', 'slot', 'vector'];

    protected $casts = ['vector' => 'array'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('embedding.database.connection'));
        $this->setTable(config('embedding.database.embeddings_table'));
    }

    public function embeddable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The payload record sharing this embedding's morph pair. Read
     * convenience only — the search path never goes through here.
     */
    public function payloadRecord(): ?Embeddable
    {
        return Embeddable::query()
            ->where('embeddable_type', $this->embeddable_type)
            ->where('embeddable_id', $this->embeddable_id)
            ->first();
    }
}
