<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Enums\BacklogItemKind;
use Illuminate\Support\Str;

/**
 * Phase 3 — the model's ONLY act of authorship, and the ceiling of its agency: it turns a reader's
 * free-text idea for the tool into a structured backlog item (a kind, a short title, a note). It
 * classifies and tidies; it does NOT build, plan, or act on the idea — the item is just a line for a
 * human to review later.
 *
 * Safe by construction: if the local model is unreachable or returns something unusable, it falls
 * back to storing the raw text as a Task rather than losing the idea (no silent failure). Injected
 * {@see ChatClient}, so it is unit-testable with a fake and never needs a running model.
 */
final class BacklogCapture
{
    private const PROMPT = <<<'TXT'
        You turn a person's free-text idea for improving a UK retirement-forecasting tool into a single
        structured backlog item. Return ONLY a JSON object and nothing else, in exactly this shape:
        {"kind": "research" | "feature" | "task", "title": "a short imperative summary, max ~12 words", "note": "one or two sentences of detail"}
        Classify: research = something to investigate or check; feature = a new capability to add; task = a fix, chore or small change.
        Do NOT build, plan, write code, or offer to do the work. Only capture and classify the idea.
        TXT;

    public function __construct(private readonly ChatClient $client) {}

    /**
     * Structure a reader's idea into a backlog item. Never throws and never returns empty — an
     * unusable model reply or an unreachable model both fall back to the raw text as a Task.
     *
     * @return array{kind: BacklogItemKind, title: string, note: string}
     */
    public function structure(string $raw): array
    {
        $raw = trim($raw);

        $fallback = [
            'kind' => BacklogItemKind::Task,
            'title' => Str::limit($raw, 80, '…'),
            'note' => '',
        ];

        if ($raw === '' || ! $this->client->isAvailable()) {
            return $fallback;
        }

        try {
            $reply = $this->client->chat([
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => $raw],
            ]);
        } catch (AssistantUnavailable) {
            return $fallback;
        }

        return self::parse($reply) ?? $fallback;
    }

    /**
     * Extract the JSON object the model was asked for. Returns null (→ fallback) if there is no
     * object, it does not decode, or it carries no usable title.
     *
     * @return array{kind: BacklogItemKind, title: string, note: string}|null
     */
    private static function parse(string $reply): ?array
    {
        if (! preg_match('/\{.*\}/s', $reply, $matches)) {
            return null;
        }

        $data = json_decode($matches[0], true);
        if (! is_array($data)) {
            return null;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return [
            'kind' => BacklogItemKind::fromValueOrDefault($data['kind'] ?? null),
            'title' => Str::limit($title, 120, ''),
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }
}
