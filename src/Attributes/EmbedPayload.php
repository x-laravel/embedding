<?php

namespace XLaravel\Embedding\Attributes;

use Attribute;

/**
 * Declares the model columns copied into the payload record used for
 * filtered similarity search. Unlike EmbedOn this attribute is not
 * repeatable — the payload is a single record per entity, not per slot.
 *
 * Pass '*' to copy every column on the model instance instead of a fixed
 * list. The wildcard excludes the primary key, hidden columns, and any
 * columns named in $except; values that are not payload-compatible
 * (nested arrays, objects) are skipped instead of throwing.
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
     * Column names excluded from a wildcard declaration.
     *
     * @var array<int, string>
     */
    public array $except;

    /**
     * @param  array<int, string>|string  $fields  One column name, an array of column names, or '*' for all columns
     * @param  array<int, string>|string  $except  Column names to exclude when $fields is '*'
     */
    public function __construct(array|string $fields, array|string $except = [])
    {
        $this->fields = is_array($fields) ? $fields : [$fields];
        $this->except = is_array($except) ? $except : [$except];
    }

    /**
     * Determine if this declaration copies all columns.
     */
    public function isWildcard(): bool
    {
        return in_array('*', $this->fields, true);
    }
}
