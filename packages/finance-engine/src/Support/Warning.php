<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Support;

/**
 * A neutral, factual warning surfaced by a calculation: a pitfall the user's
 * numbers have triggered (emergency tax, MPAA, a benefit capital cliff, an
 * allowance exceeded).
 *
 * Messages state what has happened and how the rule works; they never tell the
 * user what they "should" do. That guidance-not-advice boundary is enforced
 * elsewhere by the output-phrasing test, but warnings are written to respect it.
 */
final class Warning
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {}
}
