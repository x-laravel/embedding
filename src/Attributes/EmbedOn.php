<?php

namespace XLaravel\Embedding\Attributes;

use Attribute;

/**
 * Marks the model properties that trigger embedding generation when changed.
 * Can be stacked multiple times on a class to define multiple named embedding slots.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class EmbedOn
{
    /**
     * The column names that trigger embedding generation for this slot.
     *
     * @var array<int, string>
     */
    public array $columns;

    /**
     * @param  array<int, string>|string  $columns  One field name or an array of field names
     * @param  string  $slot  The embedding slot name (default: 'default')
     */
    public function __construct(
        array|string $columns,
        public readonly string $slot = 'default',
    ) {
        $this->columns = is_array($columns) ? $columns : [$columns];
    }
}
