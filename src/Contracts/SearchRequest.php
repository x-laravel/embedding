<?php

namespace XLaravel\Embedding\Contracts;

/**
 * Immutable parameter object for similarity search. Carries everything a
 * SimilarityDriver needs so the contract survives new options (e.g. the
 * payload filter) without another signature break.
 */
final readonly class SearchRequest
{
    /**
     * @param  array<int, float>  $vector  The query vector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  array<int, mixed>|null  $ids  Restrict the search to these primary keys
     * @param  array<string, mixed>|null  $filter  Payload equality/IN constraints, ANDed together
     */
    public function __construct(
        public array $vector,
        public int $limit = 10,
        public float $threshold = 0.0,
        public ?array $ids = null,
        public string $slot = 'default',
        public ?array $filter = null,
    ) {}
}
