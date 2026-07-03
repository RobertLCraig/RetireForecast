<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * One labelled figure or statement from the forecast, e.g. ("Spendable wealth left",
 * "£154,600.00"). Facts are the assistant's whole world: they are both the context the
 * model is shown AND the allow-list its answer is grounded against ({@see ScenarioContext}),
 * so "the figures it is given" and "the figures it may state" are one thing — one home per
 * figure, the same discipline the rest of the app follows.
 */
final class AssistantFact
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
    ) {}
}
