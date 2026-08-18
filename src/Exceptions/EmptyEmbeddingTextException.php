<?php

namespace XLaravel\Embedding\Exceptions;

use RuntimeException;

/**
 * Thrown when a model's resolved embedding text for a slot is blank. The AI
 * provider rejects empty-string input, so generation is skipped before ever
 * making the API call.
 */
class EmptyEmbeddingTextException extends RuntimeException
{
    public static function forSlot(string $modelClass, int|string $id, string $slot): self
    {
        return new self("Resolved embedding text for {$modelClass}#{$id} slot '{$slot}' is blank; skipping generation.");
    }
}
