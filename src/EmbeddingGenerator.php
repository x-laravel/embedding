<?php

namespace XLaravel\Embedding;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\EmbeddingClient;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Events\ModelEmbedded;
use XLaravel\Embedding\Events\ModelEmbedding;
use XLaravel\Embedding\Exceptions\EmptyEmbeddingTextException;
use XLaravel\Embedding\Models\Embedding;

class EmbeddingGenerator
{
    public function __construct(
        private readonly VectorStore $store,
        private readonly EmbeddingClient $client,
    ) {}

    public function generate(Model $model, string $slot = 'default'): Embedding
    {
        $text = $this->resolveText($model, $slot);

        if (blank($text)) {
            throw EmptyEmbeddingTextException::forSlot(get_class($model), $model->getKey(), $slot);
        }

        $model->fireEmbeddingModelEvent('embedding', $slot);
        event(new ModelEmbedding($model, $slot));

        $vector = $this->client->embed($this->truncate($text));

        $embeddingRecord = $this->store->store($model, $vector, $slot);

        $model->fireEmbeddingModelEvent('embedded', $slot);
        event(new ModelEmbedded($model, $embeddingRecord, $slot));

        return $embeddingRecord;
    }

    private function resolveText(Model $model, string $slot): string
    {
        $slotMap = $model->embeddingSlotMap();

        // Passing an undeclared slot straight through would let a
        // toEmbeddingText() that ignores its argument silently write a
        // duplicate row under the requested slot key. Reject the call
        // instead. 'default' stays callable on models with no slot map
        // (manual embed()/embedSync() on a model without $embeddable).
        if (! array_key_exists($slot, $slotMap) && ! ($slot === 'default' && $slotMap === [])) {
            $class = get_class($model);
            $expected = array_keys($slotMap);
            $expectedList = $expected === [] ? '(none)' : "['".implode("', '", $expected)."']";

            throw new \InvalidArgumentException(
                "Slot '{$slot}' was requested but is not defined for {$class}.\n".
                "  Defined slots (from embeddingSlotMap): {$expectedList}\n".
                "  Fix: call embed()/embedSync() with a defined slot, or add '{$slot}' to \$embeddable / #[EmbedOn] in {$class}."
            );
        }

        return $model->toEmbeddingText($slot);
    }

    private function truncate(string $text): string
    {
        $maxLength = config('embedding.max_length');

        if ($maxLength === null) {
            return $text;
        }

        return mb_substr($text, 0, (int) $maxLength);
    }
}
