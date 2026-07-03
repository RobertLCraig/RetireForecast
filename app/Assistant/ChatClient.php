<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * A local chat model the assistant talks to. Abstracted so the trust-critical
 * orchestration ({@see AssistantService}) and its guardrails can be unit-tested with a
 * fake, never depending on a running model — the same inject-don't-touch-the-runtime
 * discipline the engine uses for its clock.
 *
 * Implementations must be LOCAL only (the household's financial data must never leave the
 * machine) and must fail loudly: an unreachable model throws {@see AssistantUnavailable}
 * rather than returning an empty or fabricated answer (no silent failure).
 */
interface ChatClient
{
    /** Whether the local runtime is reachable right now (a cheap probe, not a generation). */
    public function isAvailable(): bool;

    /**
     * Generate a reply to the given conversation.
     *
     * @param  list<array{role: string, content: string}>  $messages  system + prior turns + the new user turn
     * @return string the assistant's reply text (reasoning traces stripped)
     *
     * @throws AssistantUnavailable when the local model cannot be reached or errors
     */
    public function chat(array $messages): string;
}
