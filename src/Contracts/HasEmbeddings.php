<?php

namespace XLaravel\Embedding\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

interface HasEmbeddings
{
    /**
     * Determine if the model has a stored embedding for the given slot.
     */
    public function hasEmbedding(string $slot = 'default'): bool;

    /**
     * Return the text (or per-slot texts) used to generate embeddings.
     * Return a string for a single default slot, or an associative array for multiple slots.
     *
     * @return string|array<string, string>
     */
    public function toEmbeddingText(): string|array;

    /**
     * Dispatch a job to generate the embedding for the given slot asynchronously.
     */
    public function embed(string $slot = 'default'): void;

    /**
     * Generate and store the embedding for the given slot synchronously.
     */
    public function embedSync(string $slot = 'default'): void;

    /**
     * Get a single embedding relationship scoped to the given slot.
     */
    public function embedding(string $slot = 'default'): MorphOne;

    /**
     * Get all embedding records for this model across all slots.
     */
    public function embeddings(): MorphMany;

    /**
     * Return the slot→fields map derived from $embeddable and #[EmbedOn] attributes.
     *
     * @return array<string, array<int, string>>
     */
    public function embeddingSlotMap(): array;

    /**
     * Return slot names that should be re-embedded given the set of changed field keys.
     *
     * @param  array<int, string>  $changedKeys
     * @return array<int, string>
     */
    public function slotsToEmbed(array $changedKeys): array;

    /**
     * Determine if the embedding should be preserved on soft delete.
     */
    public function keepEmbeddingOnSoftDelete(): bool;

    /**
     * Fire an embedding model event, passing the slot to listeners.
     */
    public function fireEmbeddingModelEvent(string $event, string $slot): void;

    /**
     * Compute the cosine similarity score between this model and another model or vector.
     *
     * @param  self|array<int, float>  $other
     */
    public function similarityTo(self|array $other, string $slot = 'default'): float;

    /**
     * Find the most similar models to this model, excluding itself.
     *
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public function mostSimilar(int $limit = 10, float $threshold = 0.0, string $slot = 'default'): Collection;

    /**
     * Find models most similar to the given query vector.
     *
     * @param  array<int, float>  $queryVector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  \Closure|null  $where  Additional Eloquent constraints applied before the search
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function similarTo(array $queryVector, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default'): Collection;

    /**
     * Find models most similar to the given text query.
     *
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  \Closure|null  $where  Additional Eloquent constraints applied before the search
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function similarToText(string $text, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default'): Collection;

    /**
     * Rank an existing collection of models by their similarity to the given text or vector.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $models
     * @param  string|array<int, float>  $query  Text or pre-computed query vector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function rankByRelevance(iterable $models, string|array $query, float $threshold = 0.0, string $slot = 'default'): Collection;

    /**
     * Execute a callback without triggering embedding generation.
     *
     * @return mixed
     */
    public static function withoutEmbedding(Closure $callback): mixed;

    /**
     * Disable embedding generation for the model class.
     */
    public static function disableEmbedding(): void;

    /**
     * Enable embedding generation for the model class.
     */
    public static function enableEmbedding(): void;
}
