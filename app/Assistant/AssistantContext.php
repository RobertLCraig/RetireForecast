<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * The shared contract for what the assistant reasons over — one scenario ({@see ScenarioContext})
 * or several plans compared side by side ({@see ComparisonContext}). Both are the SINGLE source of
 * two things at once: the figures shown to the model AND the grounding allow-list its answer is
 * checked against ({@see promptBlock()}), so the model can only be given, and can only legitimately
 * state, exactly these engine figures (guardrail G1). {@see SystemPrompt} and {@see AssistantService}
 * work against this contract, so a new kind of context (a comparison, a portfolio) drops in without
 * touching the orchestration or the guardrails.
 */
interface AssistantContext
{
    /**
     * The labelled figure block: BOTH shown to the model as context AND used as the grounding
     * source its answer is verified against.
     */
    public function promptBlock(): string;

    /**
     * Whether the block carries Monte Carlo probabilities/ranges (so the prompt tells the model to
     * keep "how likely" apart from the central "what happens"). False for a purely deterministic context.
     */
    public function includesMonteCarlo(): bool;

    /**
     * The one-sentence role framing for the system prompt — "explain THIS forecast" vs "COMPARE these
     * plans" — so the same prompt builder serves either without knowing which it holds.
     */
    public function systemIntro(): string;
}
