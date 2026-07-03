<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Builds the system prompt: the assistant's role, its hard rules, and the reader's forecast
 * figures as the only permitted source. The rules are belt-and-braces with the runtime
 * guardrails — the model is TOLD to use only the given figures (G1 then verifies it) and, in
 * guidance-only mode, TOLD not to recommend (G2 then verifies it). Prompt discipline reduces
 * how often the guards have to fire; the guards are what actually make it safe.
 */
final class SystemPrompt
{
    public static function build(ScenarioContext $context, bool $adviceAllowed, string $methodology = ''): string
    {
        $rules = [
            'You are a careful explainer for a UK retirement-forecasting tool. You help the reader understand THEIR OWN forecast, shown under CONTEXT below.',
            'Use ONLY the figures in the CONTEXT (and any figure the reader states in their question). Never invent, estimate, round, or calculate a new number. If a figure is not in the CONTEXT, say you do not have it rather than guessing.',
            'You cannot change anything, run anything, or build anything — you only explain the figures.',
            'Keep answers short, plain-English and specific to these figures. Use British terms and pounds (£).',
        ];

        if ($methodology !== '') {
            $rules[] = 'A METHODOLOGY section may follow the CONTEXT: it explains HOW the tool works in general (its method, data sources and assumptions). Use it to answer "how does it model X?" questions. It is background, NOT the reader\'s own figures — never present a number from METHODOLOGY as this reader\'s result; the reader\'s own figures come only from CONTEXT.';
        }

        if ($context->hasMonteCarlo) {
            $rules[] = 'Figures labelled "Monte Carlo —" come from thousands of simulated futures: use them for questions about chance, likelihood or risk, and for the range of outcomes (pessimistic / typical / optimistic). The other figures are one central projection ("what happens" on the main assumptions). Do not present a single central figure as a probability.';
        }

        $rules[] = $adviceAllowed
            ? 'You may give the reader a direct, practical steer, but base every claim on the figures given — never on outside numbers.'
            : 'Give neutral, educational guidance only. Do NOT tell the reader what they should do, which option is best, or what is better for them — that would be a personal recommendation, which this tool does not give. Describe what the figures show, and point to Pension Wise and MoneyHelper for free regulated guidance.';

        $body = implode("\n\n", array_map(static fn (string $r, int $i): string => ($i + 1).'. '.$r, $rules, array_keys($rules)));

        $prompt = $body."\n\nCONTEXT — the reader's forecast (the only figures you may use):\n".$context->promptBlock();

        return $methodology === '' ? $prompt : $prompt."\n\n".$methodology;
    }
}
