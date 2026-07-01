<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

/**
 * The result of backtesting a plan across every eligible historical starting year: one
 * {@see HistoricalBacktestOutcome} per start year, plus summary readings the panel uses
 * (how many starts the plan survived, the survival rate, and the single worst start).
 */
final class HistoricalBacktestResult
{
    /**
     * @param  list<HistoricalBacktestOutcome>  $outcomes  ordered by start year, ascending
     */
    public function __construct(public readonly array $outcomes) {}

    public function count(): int
    {
        return count($this->outcomes);
    }

    /** Start years where the essential floor held every year. */
    public function survivedCount(): int
    {
        return count(array_filter($this->outcomes, fn (HistoricalBacktestOutcome $o): bool => $o->essentialsAlwaysMet));
    }

    /** Fraction of tested historical starts the plan survived (0..1); 0.0 if none tested. */
    public function survivalRate(): float
    {
        return $this->outcomes === [] ? 0.0 : $this->survivedCount() / $this->count();
    }

    public function forStartYear(int $startYear): ?HistoricalBacktestOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->startYear === $startYear) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * The single worst historical start: the earliest depletion if any start ran the money
     * out, otherwise (all survived) the start left with the least usable wealth at the end.
     */
    public function worst(): ?HistoricalBacktestOutcome
    {
        if ($this->outcomes === []) {
            return null;
        }

        $depleted = array_filter($this->outcomes, fn (HistoricalBacktestOutcome $o): bool => $o->depletionCalendarYear !== null);
        if ($depleted !== []) {
            return array_reduce(
                $depleted,
                fn (?HistoricalBacktestOutcome $worst, HistoricalBacktestOutcome $o): HistoricalBacktestOutcome => $worst === null || $o->depletionCalendarYear < $worst->depletionCalendarYear ? $o : $worst,
            );
        }

        return array_reduce(
            $this->outcomes,
            fn (?HistoricalBacktestOutcome $worst, HistoricalBacktestOutcome $o): HistoricalBacktestOutcome => $worst === null || $o->terminalUsableWealth->pence < $worst->terminalUsableWealth->pence ? $o : $worst,
        );
    }
}
