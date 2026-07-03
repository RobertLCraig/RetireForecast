<?php

declare(strict_types=1);

namespace App\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An {@see EmbeddingClient} backed by a local Ollama server (default localhost:11434), the doc-RAG
 * counterpart of {@see OllamaChatClient}. Local-only by construction — the docs are embedded on this
 * machine and the vectors never leave it — and it fails loudly: any transport error surfaces as
 * {@see AssistantUnavailable}, so the caller reports "methodology search isn't available" rather than
 * fabricating a vector or a match.
 */
final class OllamaEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 120,
        private readonly int $probeTimeout = 2,
    ) {}

    public function isAvailable(): bool
    {
        try {
            return Http::timeout($this->probeTimeout)
                ->get($this->url('/api/tags'))
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        $inputs = array_values($texts);
        if ($inputs === []) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeout)->post($this->url('/api/embed'), [
                'model' => $this->model,
                'input' => $inputs,
            ]);
        } catch (ConnectionException $e) {
            throw new AssistantUnavailable('the local embedding model could not be reached ('.$e->getMessage().')', 0, $e);
        } catch (Throwable $e) {
            throw new AssistantUnavailable('the local embedding request failed ('.$e->getMessage().')', 0, $e);
        }

        if (! $response->successful()) {
            throw new AssistantUnavailable("the local embedding model returned HTTP {$response->status()}");
        }

        /** @var list<list<float>> $vectors */
        $vectors = $response->json('embeddings', []);
        if (count($vectors) !== count($inputs)) {
            throw new AssistantUnavailable('the local embedding model returned '.count($vectors).' vectors for '.count($inputs).' inputs');
        }

        return array_map(
            static fn (array $v): array => array_map(static fn ($x): float => (float) $x, $v),
            $vectors,
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
