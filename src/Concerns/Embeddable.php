<?php

namespace XLaravel\Embedding\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\EmbeddingGenerator;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Observers\EmbeddingObserver;
use XLaravel\Embedding\Similarity\Metrics;
use XLaravel\Embedding\SimilarityManager;

trait Embeddable
{
    /**
     * Boot the embeddable trait for a model.
     */
    public static function bootEmbeddable(): void
    {
        $whenBootedCallback = function () {
            static::observe(new EmbeddingObserver());
        };

        if (method_exists(static::class, 'whenBooted')) {
            static::whenBooted($whenBootedCallback);
        } else {
            $whenBootedCallback();
        }
    }

    /**
     * Initialize the embeddable trait for a model instance.
     */
    public function initializeEmbeddable(): void
    {
        $this->addObservableEvents(['embedding', 'embedded']);
    }

    /**
     * Fire an embedding model event, forwarding the slot to listeners.
     * Listeners may accept ($model) or ($model, $slot).
     */
    public function fireEmbeddingModelEvent(string $event, string $slot): void
    {
        if (isset(static::$dispatcher)) {
            static::$dispatcher->dispatch(
                "eloquent.{$event}: ".static::class,
                [$this, $slot]
            );
        }
    }

    /**
     * Register a listener for the "embedding" model event.
     */
    public static function onEmbedding(Closure|string $callback): void
    {
        static::registerModelEvent('embedding', $callback);
    }

    /**
     * Register a listener for the "embedded" model event.
     */
    public static function onEmbedded(Closure|string $callback): void
    {
        static::registerModelEvent('embedded', $callback);
    }

    /**
     * Determine if the model has a stored embedding for the given slot.
     */
    public function hasEmbedding(string $slot = 'default'): bool
    {
        return $this->embedding($slot)->exists();
    }

    /**
     * Get the embedding relationship scoped to a specific slot.
     */
    public function embedding(string $slot = 'default'): MorphOne
    {
        return $this->morphOne(config('embedding.model'), 'embeddable')
            ->where('slot', $slot);
    }

    /**
     * Get all embedding records for this model across all slots.
     */
    public function embeddings(): MorphMany
    {
        return $this->morphMany(config('embedding.model'), 'embeddable');
    }

    /**
     * Dispatch a job to generate the embedding for the given slot asynchronously.
     */
    public function embed(string $slot = 'default'): void
    {
        dispatch(new GenerateModelEmbedding($this, $slot));
    }

    /**
     * Generate and store the embedding for the given slot synchronously.
     */
    public function embedSync(string $slot = 'default'): void
    {
        app(EmbeddingGenerator::class)->generate($this, $slot);
    }

    /**
     * Return the slot→fields map derived from $embeddable and #[EmbedOn] attributes.
     * Used by the observer and artisan command to determine which slots need re-embedding.
     *
     * @return array<string, array<int, string>>
     */
    public function embeddingSlotMap(): array
    {
        $slotMap = [];

        $embeddable = $this->embeddable ?? [];

        if (! empty($embeddable)) {
            if (array_is_list($embeddable)) {
                // Flat list → single default slot
                $slotMap['default'] = array_merge($slotMap['default'] ?? [], $embeddable);
            } else {
                // Nested map → multiple named slots
                foreach ($embeddable as $slot => $fields) {
                    $slotMap[$slot] = array_merge($slotMap[$slot] ?? [], (array) $fields);
                }
            }
        }

        foreach ((new \ReflectionClass(static::class))->getAttributes(EmbedOn::class) as $attr) {
            $embedOn = $attr->newInstance();
            $slot = $embedOn->slot;
            $slotMap[$slot] = array_merge($slotMap[$slot] ?? [], $embedOn->columns);
        }

        return $slotMap;
    }

    /**
     * Return slot names that should be re-embedded given the set of changed field keys.
     *
     * @param  array<int, string>  $changedKeys
     * @return array<int, string>
     */
    public function slotsToEmbed(array $changedKeys): array
    {
        $slotMap = $this->embeddingSlotMap();

        if (empty($slotMap)) {
            return [];
        }

        // After an insert, Eloquent does not call syncChanges(), so getChanges() returns [].
        // wasRecentlyCreated is the only signal that all slots should be seeded.
        // But if changedKeys is non-empty (e.g. a subsequent update on the same instance),
        // skip this branch and use the field-based check below.
        if ($this->wasRecentlyCreated && empty($changedKeys)) {
            return array_keys($slotMap);
        }

        $slots = [];

        foreach ($slotMap as $slot => $fields) {
            if (in_array('*', $fields) || ! empty(array_intersect($changedKeys, $fields))) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Determine if the embedding should be preserved on soft delete.
     */
    public function keepEmbeddingOnSoftDelete(): bool
    {
        if (property_exists($this, 'keepEmbeddingOnSoftDelete')) {
            return $this->keepEmbeddingOnSoftDelete;
        }

        return config('embedding.soft_delete', false);
    }

    /**
     * Disable embedding generation for the model class.
     */
    public static function disableEmbedding(): void
    {
        EmbeddingObserver::disableSyncingFor(static::class);
    }

    /**
     * Enable embedding generation for the model class.
     */
    public static function enableEmbedding(): void
    {
        EmbeddingObserver::enableSyncingFor(static::class);
    }

    /**
     * Execute a callback without triggering embedding generation.
     *
     * @return mixed
     */
    public static function withoutEmbedding(Closure $callback): mixed
    {
        static::disableEmbedding();

        try {
            return $callback();
        } finally {
            static::enableEmbedding();
        }
    }

    /**
     * Find models most similar to the given query vector.
     *
     * @param  array<int, float>  $queryVector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  \Closure|null  $where  Additional Eloquent constraints applied before the search
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function similarTo(array $queryVector, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default'): Collection
    {
        $ids = null;

        if ($where !== null) {
            $prototype = new static();
            $ids = static::query()->tap($where)->pluck($prototype->getKeyName())->all();
        }

        return app(SimilarityManager::class)->search(new static(), $queryVector, $limit, $threshold, $ids, $slot);
    }

    /**
     * Compute the cosine similarity score between this model and another model or vector.
     *
     * @param  \XLaravel\Embedding\Contracts\HasEmbeddings|array<int, float>  $other
     */
    public function similarityTo(HasEmbeddings|array $other, string $slot = 'default'): float
    {
        $vectorA = $this->embedding($slot)->first()?->vector;

        if ($vectorA === null) {
            return 0.0;
        }

        $vectorB = $other instanceof HasEmbeddings
            ? $other->embedding($slot)->first()?->vector
            : $other;

        if ($vectorB === null || empty($vectorB)) {
            return 0.0;
        }

        return Metrics::cosine($vectorA, $vectorB);
    }

    /**
     * Find the most similar models to this model, excluding itself.
     *
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public function mostSimilar(int $limit = 10, float $threshold = 0.0, string $slot = 'default'): Collection
    {
        $vector = $this->embedding($slot)->first()?->vector;

        if ($vector === null) {
            return new Collection();
        }

        $selfKey = $this->getKey();

        return static::similarTo($vector, $limit + 1, $threshold, slot: $slot)
            ->filter(fn ($m) => $m->getKey() !== $selfKey)
            ->take($limit)
            ->values();
    }

    /**
     * Find models most similar to the given text query.
     *
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  \Closure|null  $where  Additional Eloquent constraints applied before the search
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function similarToText(string $text, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default'): Collection
    {
        $vector = Embeddings::for([$text])->generate()->first();

        return static::similarTo($vector, $limit, $threshold, $where, $slot);
    }

    /**
     * Rank an existing collection of models by their similarity to the given text or vector.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $models
     * @param  string|array<int, float>  $query  Text or pre-computed query vector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function rankByRelevance(iterable $models, string|array $query, float $threshold = 0.0, string $slot = 'default'): Collection
    {
        $queryVector = is_array($query)
            ? $query
            : Embeddings::for([$query])->generate()->first();

        $collection = Collection::make($models);
        $collection->loadMissing('embeddings');

        $scored = [];
        foreach ($collection as $model) {
            $embeddingRecord = $model->embeddings->firstWhere('slot', $slot);
            $vector = $embeddingRecord?->vector ?? [];
            $score = empty($vector) ? 0.0 : Metrics::cosine($queryVector, $vector);
            $model->setAttribute('similarity_score', $score);
            $scored[] = $model;
        }

        $collection = Collection::make($scored)
            ->sortByDesc(fn ($m) => $m->getAttribute('similarity_score'));

        if ($threshold > 0.0) {
            $collection = $collection->filter(fn ($m) => $m->getAttribute('similarity_score') >= $threshold);
        }

        return $collection->values();
    }
}
