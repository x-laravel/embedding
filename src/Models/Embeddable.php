<?php

namespace XLaravel\Embedding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Embeddable extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('embedding.database.connection'));
        $this->setTable(config('embedding.database.embeddables_table'));
    }

    public function embeddable(): MorphTo
    {
        return $this->morphTo();
    }
}
