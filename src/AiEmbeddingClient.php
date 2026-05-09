<?php

namespace XLaravel\Embedding;

use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Contracts\EmbeddingClient;

class AiEmbeddingClient implements EmbeddingClient
{
    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        return Embeddings::for([$text])->generate()->first();
    }
}
