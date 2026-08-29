<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Forecast\WhatIfWriter;
use App\Livewire\ScenarioAssistant;

/**
 * Turns what the reader said into a PROPOSED set of scenario edits — the assistant's
 * data-entry role, and the whole of its agency here. It fills in a form from the reader's own
 * words; it never forecasts, never supplies a figure, and never writes anything: the caller
 * shows the proposal for confirmation ({@see ScenarioAssistant}) and only a
 * click persists it ({@see WhatIfWriter}).
 *
 * The model is handed a closed menu ({@see ScenarioEditVocabulary}, guardrail C2) and returns
 * only a selection plus the reader's value. Every returned edit is then re-checked here, so
 * nothing rests on the model behaving:
 *
 *   1. the target is on the menu, or it is dropped (C2);
 *   2. the value coerces to that target's type, or it is dropped;
 *   3. the value is a figure the reader actually stated ({@see ScenarioEditGrounding}, C1);
 *   4. a value equal to what the plan already says is dropped, so a no-op makes nothing.
 *
 * If nothing survives, the reader gets a QUESTION back rather than a guess. Unlike
 * {@see BacklogCapture}, there is no "save the raw text" fallback — an unclear edit must not
 * become a scenario (SE-8). Injected {@see ChatClient}, so it is unit-testable with a fake.
 */
final class ScenarioEditCapture
{
    private const PROMPT = <<<'TXT'
        You fill in a form. A person wants to change one or more figures in their own UK retirement
        plan, to try it out. You are given a MENU of the only fields that can be changed; each line is
          field-id | what it is | type | the value now
        Return ONLY a JSON object and nothing else, in exactly this shape:
        {"edits": [{"field": "a field-id copied exactly from the menu", "value": "the new value"}], "question": ""}
        Rules:
        - Only ever use a field-id copied exactly from the menu. Never invent one.
        - Only ever use a figure the person themselves stated. Never supply a figure of your own,
          never round one, and never work one out for them.
        - Write a value as a plain number: no currency sign, no commas, no percent sign (68, 32000, 3.5).
          For a field of type enum, use one of the options listed for it.
        - If two fields could be meant, or they gave no figure, return an empty "edits" list and put ONE
          short question in "question" asking them which field or what figure.
        - Never say what a change would do to their forecast. You are filling in a form, not forecasting.
        TXT;

    public function __construct(private readonly ChatClient $client) {}

    /**
     * A validated proposal for $request against $targets: the edits that survived every check,
     * or an empty edit list plus a question to put back to the reader. Never throws.
     *
     * @param  list<EditTarget>  $targets
     * @return array{edits: array<string, string>, question: string}
     */
    public function propose(array $targets, string $request): array
    {
        $request = trim($request);

        if ($targets === []) {
            return self::ask('There is nothing in this plan the assistant can change for you yet.');
        }
        if ($request === '') {
            return self::ask('Tell me what to change, and what to change it to.');
        }
        if (! $this->client->isAvailable()) {
            return self::ask("The local assistant isn't running, so it can't read that. Nothing has changed.");
        }

        try {
            $reply = $this->client->chat([
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => "MENU:\n".ScenarioEditVocabulary::menu($targets)."\n\nWHAT THEY SAID:\n".$request],
            ]);
        } catch (AssistantUnavailable) {
            return self::ask('The local assistant stopped before it could read that. Nothing has changed.');
        }

        return self::validate($targets, self::parse($reply), $request);
    }

    /**
     * Re-check the model's selection against the menu, the reader's words and the plan's
     * current values. Anything that fails is dropped with a reason; if nothing is left, the
     * reason (or the model's own question) goes back to the reader.
     *
     * @param  list<EditTarget>  $targets
     * @param  array{edits: list<array{field: string, value: string}>, question: string}|null  $parsed
     * @return array{edits: array<string, string>, question: string}
     */
    private static function validate(array $targets, ?array $parsed, string $request): array
    {
        if ($parsed === null) {
            return self::ask("I couldn't follow that. Try naming one figure and what to change it to.");
        }

        $edits = [];
        $reason = '';

        foreach ($parsed['edits'] as $edit) {
            $target = ScenarioEditVocabulary::find($targets, $edit['field']);
            if ($target === null) {
                $reason = 'I can only change the figures listed in this plan. Which one did you mean?';

                continue;
            }

            $value = self::coerce($target, $edit['value']);
            if ($value === null) {
                $reason = "I couldn't read \"{$edit['value']}\" as a value for {$target->label}. What should it be?";

                continue;
            }
            if (! ScenarioEditGrounding::isStated($value, $request)) {
                // C1: a figure the reader never said is one the model made up. Never propose it.
                $reason = "I'd only be guessing at a figure for {$target->label}. What should it be?";

                continue;
            }
            if ($value === self::coerce($target, $target->currentValue)) {
                $reason = "{$target->label} is already {$target->currentDisplay()}, so there is nothing to change.";

                continue;
            }

            $edits[$target->path] = $value;
        }

        if ($edits !== []) {
            return ['edits' => $edits, 'question' => ''];
        }

        return self::ask($parsed['question'] !== '' ? $parsed['question'] : ($reason ?: 'Tell me which figure to change, and what to change it to.'));
    }

    /**
     * A value normalised to the form-state's own shape (plain strings), or null when it is not
     * a value this target can take. £ signs, commas, percent signs and a k/m suffix are
     * tolerated: the reader's phrasing reaches the model, and the reader is the source.
     */
    private static function coerce(EditTarget $target, string $raw): ?string
    {
        $raw = trim($raw);

        if ($target->type === 'enum') {
            return in_array($raw, $target->options, true) ? $raw : null;
        }

        if (! preg_match('/^[£$]?\s?(\d[\d,]*(?:\.\d+)?)\s?([kmKM])?\s?%?$/u', $raw, $match)) {
            return null;
        }

        $number = (float) str_replace(',', '', $match[1]);
        $number = match (strtolower($match[2] ?? '')) {
            'k' => $number * 1_000,
            'm' => $number * 1_000_000,
            default => $number,
        };

        return $target->type === 'int'
            ? (string) (int) round($number)
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * The JSON object the model was asked for, tolerantly extracted. Null when there is none,
     * it does not decode, or it carries neither an edit nor a question.
     *
     * @return array{edits: list<array{field: string, value: string}>, question: string}|null
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

        $edits = [];
        foreach (is_array($data['edits'] ?? null) ? $data['edits'] : [] as $edit) {
            if (is_array($edit) && isset($edit['field'], $edit['value']) && ! is_array($edit['value'])) {
                $edits[] = ['field' => trim((string) $edit['field']), 'value' => (string) $edit['value']];
            }
        }

        $question = trim((string) ($data['question'] ?? ''));

        return $edits === [] && $question === '' ? null : ['edits' => $edits, 'question' => $question];
    }

    /** @return array{edits: array<string, string>, question: string} */
    private static function ask(string $question): array
    {
        return ['edits' => [], 'question' => $question];
    }
}
