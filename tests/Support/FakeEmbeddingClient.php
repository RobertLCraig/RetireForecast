<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Assistant\AssistantUnavailable;
use App\Assistant\EmbeddingClient;
use App\Assistant\MethodologyRetriever;

/**
 * A scripted {@see EmbeddingClient} for testing the doc-RAG pieces ({@see MethodologyRetriever})
 * with no running model. It maps a text to a vector by the first substring it contains (so a test can say
 * "a query mentioning 'emergency' embeds near the emergency-tax chunk"), falls back to a default vector, and
 * can simulate an unreachable runtime.
 */
final class FakeEmbeddingClient implements EmbeddingClient
{
    /**
     * @param  array<string, list<float>>  $vectors  substring => vector returned when the input contains it
     * @param  list<float>  $default  vector returned when no substring matches
     */
    public function __construct(
        private array $vectors = [],
        private array $default = [],
        private bool $available = true,
        private bool $throw = false,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function embed(string $text): array
    {
        if ($this->throw) {
            throw new AssistantUnavailable('simulated embedding failure');
        }

        foreach ($this->vectors as $needle => $vector) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return $vector;
            }
        }

        return $this->default;
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $t): array => $this->embed($t), array_values($texts));
    }
}
