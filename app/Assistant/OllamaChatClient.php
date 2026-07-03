<?php

declare(strict_types=1);

namespace App\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A {@see ChatClient} backed by a local Ollama server (default localhost:11434).
 *
 * Local-only by construction: it talks to the configured base URL and nothing else, so the
 * household's financial data never leaves the machine. Any transport failure surfaces as
 * {@see AssistantUnavailable} — the caller then reports it, never fabricates around it.
 *
 * Reasoning models (e.g. qwen3) emit a <think>…</think> preamble; we ask Ollama to disable
 * it (`think: false`) and strip it defensively, so a stray trace never reaches the reader
 * or the grounding check.
 */
final class OllamaChatClient implements ChatClient
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

    public function chat(array $messages): string
    {
        try {
            $response = Http::timeout($this->timeout)->post($this->url('/api/chat'), [
                'model' => $this->model,
                'messages' => array_values($messages),
                'stream' => false,
                // Reasoning models: keep the chain-of-thought out of the reply entirely.
                'think' => false,
                'options' => [
                    // Low temperature: this is an explainer over fixed figures, not a creative task.
                    'temperature' => 0.2,
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new AssistantUnavailable('the local model could not be reached ('.$e->getMessage().')', 0, $e);
        } catch (Throwable $e) {
            throw new AssistantUnavailable('the local model request failed ('.$e->getMessage().')', 0, $e);
        }

        if (! $response->successful()) {
            throw new AssistantUnavailable("the local model returned HTTP {$response->status()}");
        }

        $content = (string) $response->json('message.content', '');

        return $this->stripReasoning($content);
    }

    /** Remove any <think>…</think> reasoning preamble a reasoning model may still emit. */
    private function stripReasoning(string $text): string
    {
        $stripped = preg_replace('/<think>.*?<\/think>/is', '', $text);

        return trim($stripped ?? $text);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
