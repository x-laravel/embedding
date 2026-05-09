<?php

namespace XLaravel\Embedding\Contracts;

interface EmbeddingClient
{
    /**
     * Produce an embedding vector for the given text.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;
}
