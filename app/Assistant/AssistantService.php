<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Compliance\OutputPhrasing;

/**
 * Orchestrates one assistant turn and enforces the two guardrails that make a local model
 * safe to point at a real household's forecast:
 *
 *   G1 (figure grounding) — every material figure in the reply must be engine-derived
 *      ({@see FigureGrounding}); a number the model invented is never shown.
 *   G2 (phrasing partition) — in guidance-only mode the reply must be clean of recommendation
 *      wording ({@see OutputPhrasing}), reusing the app's single banned-phrase home; in
 *      personal-use advice mode a direct steer is allowed.
 *
 * A failed guard gets ONE corrective retry, then the turn is refused with its reason rather
 * than shown — the tool would rather say "I held that back" than surface a wrong number or a
 * recommendation it must not make. The model is NEVER the source of a figure and never builds.
 *
 * Dependencies are injected (the {@see ChatClient}, and `adviceAllowed` + a prebuilt
 * {@see ScenarioContext} as arguments), so the whole thing is unit-testable with a fake client
 * and never needs a running model — the engine's inject-don't-touch-the-runtime discipline.
 */
final class AssistantService
{
    /** Total generations per turn: the first answer plus one corrective retry. */
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly ChatClient $client) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  prior turns, oldest first
     */
    public function answer(ScenarioContext $context, string $question, bool $adviceAllowed, array $history = []): AssistantAnswer
    {
        if (! $this->client->isAvailable()) {
            return AssistantAnswer::unavailable("The local assistant isn't running. Start Ollama (on this machine) and try again.");
        }

        $system = SystemPrompt::build($context, $adviceAllowed);
        $base = [
            ['role' => 'system', 'content' => $system],
            ...array_values($history),
            ['role' => 'user', 'content' => $question],
        ];

        $messages = $base;
        $lastPhrasing = [];
        $lastUngrounded = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $raw = trim($this->client->chat($messages));
            } catch (AssistantUnavailable $e) {
                return AssistantAnswer::unavailable("The local assistant couldn't answer: ".$e->getMessage());
            }

            $phrasing = $adviceAllowed ? [] : OutputPhrasing::violations($raw);
            $ungrounded = FigureGrounding::ungrounded($raw, $context->promptBlock(), $question);

            if ($phrasing === [] && $ungrounded === []) {
                return AssistantAnswer::answered($raw);
            }

            $lastPhrasing = $phrasing;
            $lastUngrounded = $ungrounded;

            // Re-ask, naming exactly what failed, then re-validate on the next loop.
            $messages = [
                ...$base,
                ['role' => 'assistant', 'content' => $raw],
                ['role' => 'user', 'content' => self::correction($phrasing, $ungrounded)],
            ];
        }

        // Still failing after the retry — refuse rather than show a bad answer.
        if ($lastPhrasing !== []) {
            return AssistantAnswer::refused(
                "I can explain what your figures show, but I can't tell you what you *should* do — that would be a personal recommendation, which this tool doesn't give. Ask me about the numbers and I'll walk you through them. For free regulated guidance, try Pension Wise or MoneyHelper.",
                'phrasing_refused',
                ['blocked recommendation phrasing: '.implode(', ', $lastPhrasing)],
            );
        }

        return AssistantAnswer::refused(
            "I've held that answer back: it included a figure I couldn't verify against your forecast, and I won't show a number I can't stand behind. Here are the figures I can confirm:\n\n".$context->promptBlock(),
            'ungrounded_refused',
            ['blocked ungrounded figure(s): '.implode(', ', $lastUngrounded)],
        );
    }

    /**
     * @param  list<string>  $phrasing
     * @param  list<string>  $ungrounded
     */
    private static function correction(array $phrasing, array $ungrounded): string
    {
        $parts = ['Please answer again.'];
        if ($ungrounded !== []) {
            $parts[] = 'These figures are not in the forecast I gave you: '.implode(', ', $ungrounded).'. Use only numbers from the CONTEXT; do not calculate or estimate.';
        }
        if ($phrasing !== []) {
            $parts[] = 'Remove any wording that tells me what I should do or which option is best; describe the figures neutrally instead.';
        }

        return implode(' ', $parts);
    }
}
