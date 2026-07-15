<?php

namespace XLaravel\Embedding\Attributes;

use Attribute;

/**
 * Declares the model columns copied into the payload record used for
 * filtered similarity search. Unlike EmbedOn this attribute is not
 * repeatable — the payload is a single record per entity, not per slot.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class EmbedPayload
{
    /**
     * The column names whose values are stored in the payload record.
     *
     * @var array<int, string>
     */
    public array $fields;

    /**
     * @param  array<int, string>|string  $fields  One column name or an array of column names
     */
    public function __construct(array|string $fields)
    {
        $this->fields = is_array($fields) ? $fields : [$fields];
    }
}
