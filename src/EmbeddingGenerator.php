<?php

namespace XLaravel\Embedding;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\EmbeddingClient;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Events\ModelEmbedded;
use XLaravel\Embedding\Events\ModelEmbedding;
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

        $model->fireEmbeddingModelEvent('embedding', $slot);
        event(new ModelEmbedding($model, $slot));

        $vector = $this->client->embed($text);

        $embeddingRecord = $this->store->store($model, $vector, $slot);

        $model->fireEmbeddingModelEvent('embedded', $slot);
        event(new ModelEmbedded($model, $embeddingRecord, $slot));

        return $embeddingRecord;
    }

    private function resolveText(Model $model, string $slot): string
    {
        $result = $model->toEmbeddingText();

        if (is_string($result)) {
            return $result;
        }

        if (! array_key_exists($slot, $result)) {
            $class = get_class($model);
            $returned = array_keys($result);
            $expected = array_keys($model->embeddingSlotMap());

            $returnedList = $returned === [] ? '(none)' : "['".implode("', '", $returned)."']";
            $expectedList = $expected === [] ? '(none)' : "['".implode("', '", $expected)."']";

            throw new \InvalidArgumentException(
                "Slot '{$slot}' was requested but is missing from {$class}::toEmbeddingText().\n".
                "  Returned slots: {$returnedList}\n".
                "  Expected slots (from embeddingSlotMap): {$expectedList}\n".
                "  Fix: either return '{$slot}' from toEmbeddingText(), or remove it from \$embeddable / #[EmbedOn] in {$class}."
            );
        }

        return $result[$slot];
    }
}
