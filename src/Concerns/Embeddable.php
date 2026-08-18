<?php

namespace XLaravel\Embedding\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Attributes\EmbedPayload;
use XLaravel\Embedding\Contracts\HasEmbeddings;
use XLaravel\Embedding\Contracts\PayloadStore;
use XLaravel\Embedding\Contracts\SearchRequest;
use XLaravel\Embedding\EmbeddingGenerator;
use XLaravel\Embedding\Jobs\GenerateModelEmbedding;
use XLaravel\Embedding\Observers\EmbeddingObserver;
use XLaravel\Embedding\Similarity\Metrics;
use XLaravel\Embedding\SimilarityManager;
use XLaravel\Embedding\Support\SlotQueryPlanner;

trait Embeddable
{
    /**
     * Per-class cache for embeddingSlotMap(). The map is derived purely
     * from class metadata (`$embeddable` + #[EmbedOn]) and never varies
     * between instances, so we memoize it once per class lifetime.
     *
     * @var array<class-string, array<string, array<int, string>>>
     */
    protected static array $slotMapCache = [];

    /**
     * Per-class cache for the #[EmbedPayload] attribute instance (null when
     * the class declares none). Class metadata never varies between
     * instances, so memoized like the slot map.
     *
     * @var array<class-string, EmbedPayload|null>
     */
    protected static array $embedPayloadCache = [];

    /**
     * Boot the embeddable trait for a model.
     */
    public static function bootEmbeddable(): void
    {
        static::$slotMapCache[static::class] ??= static::computeEmbeddingSlotMap();
        static::embeddingPayloadAttribute();

        $whenBootedCallback = function () {
            static::observe(new EmbeddingObserver);
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
     * Drop the cached slot map for the calling class. The next call to
     * embeddingSlotMap() will recompute from $embeddable / #[EmbedOn].
     */
    public static function flushEmbeddingSlotMapCache(): void
    {
        unset(static::$slotMapCache[static::class]);
    }

    /**
     * Drop the cached #[EmbedPayload] attribute for the calling class. The
     * next call to embeddingPayloadFields() will recompute from reflection.
     */
    public static function flushEmbeddingPayloadFieldsCache(): void
    {
        unset(static::$embedPayloadCache[static::class]);
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
     * Constrain the query to records with non-blank content in at least one
     * of the fields feeding the given slot — i.e. records that could
     * actually produce embeddable text. Slots with no declared fields (or
     * fields not present in embeddingSlotMap()) are left unconstrained.
     * The AI provider rejects empty-string input, so records that would
     * always fail generation should not be counted or queried as "missing".
     */
    public function scopeEligibleForEmbedding(Builder $query, string $slot): Builder
    {
        $fields = $this->embeddingSlotMap()[$slot] ?? [];

        if (empty($fields)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($fields) {
            foreach ($fields as $field) {
                $q->orWhere(fn (Builder $q2) => $q2->whereNotNull($field)->where($field, '!=', ''));
            }
        });
    }

    /**
     * Count records of this model that have a stored embedding for the given
     * slot. When the model and the embeddings table live on different
     * connections, whereHas cannot join across them — the embedding-side ID
     * list is plucked first and verified against the model side instead.
     */
    public static function embeddedCount(string $slot = 'default'): int
    {
        $instance = new static;
        $embeddingModel = config('embedding.model');

        $modelConnection = $instance->getConnection()->getName();
        $embeddingConnection = (new $embeddingModel)->getConnection()->getName();

        if ($modelConnection === $embeddingConnection) {
            return static::whereHas(
                'embeddings',
                fn ($query) => $query->where('slot', $slot)
            )->count();
        }

        $embeddingIds = $embeddingModel::query()
            ->where('embeddable_type', $instance->getMorphClass())
            ->where('slot', $slot)
            ->pluck('embeddable_id')
            ->all();

        if (empty($embeddingIds)) {
            return 0;
        }

        return static::query()
            ->whereIn($instance->getKeyName(), $embeddingIds)
            ->count();
    }

    /**
     * Count records missing a stored embedding. With a slot, counts records
     * lacking that slot's embedding; without, sums the missing counts across
     * every declared slot — a record missing two slots counts twice. Models
     * with no slots defined always report zero. Records whose *resolved*
     * text (toEmbeddingText(), after any per-model normalization) is blank
     * are not counted — there is nothing generation could ever do for them,
     * however non-blank their raw source columns look. This mirrors exactly
     * what BatchGenerator/embedding:vector:generate would actually dispatch
     * (via SlotQueryPlanner), so the count never drifts from reality.
     */
    public static function missingEmbeddingCount(?string $slot = null): int
    {
        $slots = $slot !== null
            ? [$slot]
            : array_keys((new static)->embeddingSlotMap());

        if (empty($slots)) {
            return 0;
        }

        $missing = 0;

        foreach ($slots as $slotName) {
            $missing += count(SlotQueryPlanner::missingIds(static::class, $slotName));
        }

        return $missing;
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
        return static::$slotMapCache[static::class]
            ??= static::computeEmbeddingSlotMap();
    }

    /**
     * Compute the slot map from class metadata. Called once per class
     * (during bootEmbeddable or on first lookup) and cached.
     *
     * @return array<string, array<int, string>>
     */
    protected static function computeEmbeddingSlotMap(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $embeddable = $reflection->getDefaultProperties()['embeddable'] ?? [];

        $slotMap = [];

        if (! empty($embeddable)) {
            if (array_is_list($embeddable)) {
                // Flat list → single default slot
                $slotMap['default'] = $embeddable;
            } else {
                // Nested map → multiple named slots
                foreach ($embeddable as $slot => $fields) {
                    $slotMap[$slot] = (array) $fields;
                }
            }
        }

        foreach ($reflection->getAttributes(EmbedOn::class) as $attr) {
            $embedOn = $attr->newInstance();
            $slotMap[$embedOn->slot] = array_merge(
                $slotMap[$embedOn->slot] ?? [],
                $embedOn->columns,
            );
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
     * Return the flat column list declared via #[EmbedPayload]. A wildcard
     * declaration ('*') expands to the instance's current attribute keys
     * minus the primary key, hidden columns, and the except list. Used for
     * dirty detection — values computed inside toEmbeddingPayload() from
     * non-column sources never appear here and therefore cannot trigger
     * an automatic payload update; call syncEmbeddingPayload() for those.
     *
     * @return array<int, string>
     */
    public function embeddingPayloadFields(): array
    {
        $attribute = static::embeddingPayloadAttribute();

        if ($attribute === null) {
            return [];
        }

        if ($attribute->isWildcard()) {
            return array_values(array_diff(
                array_keys($this->getAttributes()),
                [$this->getKeyName()],
                $this->getHidden(),
                $attribute->except,
            ));
        }

        return array_values(array_diff($attribute->fields, $attribute->except));
    }

    /**
     * Resolve (and memoize) the #[EmbedPayload] attribute instance.
     */
    protected static function embeddingPayloadAttribute(): ?EmbedPayload
    {
        if (! array_key_exists(static::class, static::$embedPayloadCache)) {
            $reflection = new \ReflectionClass(static::class);
            $attributes = $reflection->getAttributes(EmbedPayload::class);

            static::$embedPayloadCache[static::class] = empty($attributes)
                ? null
                : $attributes[0]->newInstance();
        }

        return static::$embedPayloadCache[static::class];
    }

    /**
     * Determine if the model declares a payload — via #[EmbedPayload],
     * a toEmbeddingPayload() method, or both. Models without a payload
     * never get an embeddables record.
     */
    public function hasEmbeddingPayload(): bool
    {
        return static::embeddingPayloadAttribute() !== null
            || method_exists($this, 'toEmbeddingPayload');
    }

    /**
     * Resolve the payload for this model. #[EmbedPayload] columns are read
     * through getAttribute() (accessors included); a toEmbeddingPayload()
     * method is merged on top — the method wins on key collisions.
     *
     * Wildcard mode is lenient where the explicit list is strict: dates are
     * serialized via serializeDate(), backed enums collapse to their value,
     * and anything still payload-incompatible is skipped, because '*' means
     * "take what fits", not "these exact columns must fit".
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When an explicitly declared value is neither scalar, null, nor an array of scalars
     */
    public function resolveEmbeddingPayload(): array
    {
        $wildcard = static::embeddingPayloadAttribute()?->isWildcard() ?? false;

        $payload = [];

        foreach ($this->embeddingPayloadFields() as $field) {
            $value = $this->getAttribute($field);

            if ($wildcard) {
                if ($value instanceof \DateTimeInterface) {
                    $value = $this->serializeDate($value);
                } elseif ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }

                if (! static::isValidPayloadValue($value)) {
                    continue;
                }
            }

            $payload[$field] = $value;
        }

        if (method_exists($this, 'toEmbeddingPayload')) {
            $payload = array_merge($payload, $this->toEmbeddingPayload());
        }

        foreach ($payload as $key => $value) {
            if (! static::isValidPayloadValue($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Embedding payload value [%s] on [%s] must be a scalar, null, or an array of scalars — nested structures are not supported.',
                    $key,
                    static::class,
                ));
            }
        }

        return $payload;
    }

    /**
     * A payload value must be scalar, null, or a flat array of scalars/nulls.
     */
    protected static function isValidPayloadValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($item !== null && ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any #[EmbedPayload] column is among the changed keys.
     *
     * @param  array<int, string>  $changedKeys
     */
    public function payloadFieldsChanged(array $changedKeys): bool
    {
        return ! empty(array_intersect($changedKeys, $this->embeddingPayloadFields()));
    }

    /**
     * Upsert this model's payload record immediately (no queue). Intended
     * for payloads computed from non-column sources that dirty detection
     * cannot see. No-op for models without a payload definition.
     */
    public function syncEmbeddingPayload(): void
    {
        if (! $this->hasEmbeddingPayload()) {
            return;
        }

        app(PayloadStore::class)->upsert($this, $this->resolveEmbeddingPayload());
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
     * @param  Closure|null  $where  Additional Eloquent constraints applied before the search —
     *                               best for one-off / complex / small-set restrictions
     * @param  array<string, mixed>|null  $filter  Payload equality/IN constraints, ANDed together —
     *                                             best for high-cardinality, indexable restrictions
     * @return Collection<int, static>
     */
    public static function similarTo(array $queryVector, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default', ?array $filter = null): Collection
    {
        $ids = null;

        if ($where !== null) {
            $prototype = new static;
            $ids = static::query()->tap($where)->pluck($prototype->getKeyName())->all();
        }

        return app(SimilarityManager::class)->search(new static, new SearchRequest(
            vector: $queryVector,
            limit: $limit,
            threshold: $threshold,
            ids: $ids,
            slot: $slot,
            filter: $filter,
        ));
    }

    /**
     * Compute the cosine similarity score between this model and another model or vector.
     *
     * @param  HasEmbeddings|array<int, float>  $other
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
     * @param  array<string, mixed>|null  $filter  Payload equality/IN constraints, ANDed together
     * @return Collection<int, static>
     */
    public function mostSimilar(int $limit = 10, float $threshold = 0.0, string $slot = 'default', ?array $filter = null): Collection
    {
        $vector = $this->embedding($slot)->first()?->vector;

        if ($vector === null) {
            return new Collection;
        }

        $selfKey = $this->getKey();

        return static::similarTo($vector, $limit + 1, $threshold, slot: $slot, filter: $filter)
            ->filter(fn ($m) => $m->getKey() !== $selfKey)
            ->take($limit)
            ->values();
    }

    /**
     * Find models most similar to the given text query.
     *
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @param  Closure|null  $where  Additional Eloquent constraints applied before the search —
     *                               best for one-off / complex / small-set restrictions
     * @param  array<string, mixed>|null  $filter  Payload equality/IN constraints, ANDed together —
     *                                             best for high-cardinality, indexable restrictions
     * @return Collection<int, static>
     */
    public static function similarToText(string $text, int $limit = 10, float $threshold = 0.0, ?Closure $where = null, string $slot = 'default', ?array $filter = null): Collection
    {
        // The AI provider can legitimately return zero embeddings (empty
        // input, throttled response, transient backend error). Calling
        // ->first() on an empty response would raise an undefined-key
        // error before any guard had a chance to run, so we inspect the
        // raw response and short-circuit to an empty result set instead.
        $response = Embeddings::for([$text])->generate();

        if (empty($response->embeddings)) {
            return new Collection;
        }

        return static::similarTo($response->first(), $limit, $threshold, $where, $slot, $filter);
    }

    /**
     * Rank an existing collection of models by their similarity to the given text or vector.
     *
     * @param  iterable<Model>  $models
     * @param  string|array<int, float>  $query  Text or pre-computed query vector
     * @param  float  $threshold  Minimum similarity score; 0.0 returns all results
     * @return Collection<int, static>
     */
    public static function rankByRelevance(iterable $models, string|array $query, float $threshold = 0.0, string $slot = 'default'): Collection
    {
        if (is_array($query)) {
            $queryVector = $query;
        } else {
            $response = Embeddings::for([$query])->generate();

            // Empty AI response → empty ranked collection rather than an
            // undefined-key error from EmbeddingsResponse::first().
            if (empty($response->embeddings)) {
                return new Collection;
            }

            $queryVector = $response->first();
        }

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
