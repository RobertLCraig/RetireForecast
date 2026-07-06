<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * A spending amount that varies with the reference person's age — a piecewise-constant
 * **real** path, held as ordered breakpoints (bands). Each band {fromAge, amount} holds
 * from `fromAge` until the next band begins; below the earliest band the earliest amount
 * holds (spend does not vanish before the first breakpoint).
 *
 * This is the engine's single representation of the "retirement spending smile" / phased
 * spend. It is a strict superset of every industry form: a flat spend is one band; a
 * two-band step (e.g. £60k to 75 then £40k) is two bands; go-go/slow-go/no-go staging is
 * three; a category age-band schedule (Basu/Kitces) is however many the category needs; a
 * constant %/yr decline compiles to a band per year. Convenience editors (a %/yr fade, a
 * phase template, a hand-drawn curve) all compile *down to* bands, so the store stays
 * general and reconciliation-friendly.
 *
 * **Reconciliation.** {@see amountAt} is a pure lookup, and {@see plus} sums two paths band
 * for band across the union of their breakpoints, so an aggregate path (essential /
 * discretionary) reconciles as the sum of its line paths at *every* age — the same "line
 * items are the source, totals derived" discipline as the flat totals, extended from a
 * scalar sum to a per-age sum.
 *
 * Amounts are real (today's money); the projector applies inflation, exactly as it does to
 * the flat totals this replaces.
 */
final class SpendPath
{
    /**
     * @param  non-empty-list<array{fromAge: int, amount: Money}>  $bands  ordered by fromAge ascending, distinct ages
     */
    private function __construct(
        public readonly array $bands,
    ) {}

    /** A path that never varies — a single band from age 0. The common (no-smile) case. */
    public static function flat(Money $amount): self
    {
        return new self([['fromAge' => 0, 'amount' => $amount]]);
    }

    /**
     * Build from a list of bands. Bands are sorted by age; the list must be non-empty and
     * carry no duplicate `fromAge` (an ambiguous double definition of one age).
     *
     * @param  list<array{fromAge: int, amount: Money}>  $bands
     */
    public static function fromBands(array $bands): self
    {
        if ($bands === []) {
            throw new InvalidArgumentException('A SpendPath needs at least one band.');
        }

        usort($bands, fn (array $a, array $b): int => $a['fromAge'] <=> $b['fromAge']);

        $seen = [];
        foreach ($bands as $band) {
            if (isset($seen[$band['fromAge']])) {
                throw new InvalidArgumentException("Duplicate band at age {$band['fromAge']}.");
            }
            $seen[$band['fromAge']] = true;
        }

        return new self(array_values($bands));
    }

    /**
     * The real spend for a reference person of the given age: the amount of the last band
     * whose `fromAge` is at or below `$age`. Below the earliest band, the earliest amount
     * holds (the path is clamped, not zeroed, before its first breakpoint).
     */
    public function amountAt(int $age): Money
    {
        $amount = $this->bands[0]['amount'];
        foreach ($this->bands as $band) {
            if ($band['fromAge'] <= $age) {
                $amount = $band['amount'];

                continue;
            }
            break;
        }

        return $amount;
    }

    /**
     * The starting (earliest-band) amount — the "headline" spend at the start of the path.
     * For a flat path this is the whole amount; for a smile it is the go-go figure. Used by
     * the flat, non-age-aware consumers (benchmarks, presenters, sweep levers) that read a
     * single representative spend.
     */
    public function startAmount(): Money
    {
        return $this->bands[0]['amount'];
    }

    /** True when the path never varies (a single band): the no-smile case. */
    public function isFlat(): bool
    {
        return count($this->bands) === 1;
    }

    /**
     * The sum of two paths: a path whose value at every age is this path's amount plus the
     * other's. Its breakpoints are the union of both, so summing line paths into an aggregate
     * loses no step. The reconciliation primitive.
     */
    public function plus(self $other): self
    {
        $ages = [];
        foreach ([...$this->bands, ...$other->bands] as $band) {
            $ages[$band['fromAge']] = true;
        }
        ksort($ages);

        $bands = [];
        foreach (array_keys($ages) as $age) {
            $bands[] = ['fromAge' => $age, 'amount' => $this->amountAt($age)->plus($other->amountAt($age))];
        }

        return new self($bands);
    }

    /**
     * This path with a flat amount added to every band — for adding a contingent flat cost
     * (e.g. a new mortgage payment) that does not itself vary with age.
     */
    public function plusFlat(Money $amount): self
    {
        return $this->mapAmounts(fn (Money $m): Money => $m->plus($amount));
    }

    /**
     * This path with a flat amount removed from every band, never below zero — for dropping a
     * contingent flat cost (property / employment costs) from a floor that otherwise smiles.
     */
    public function minusFlat(Money $amount): self
    {
        return $this->mapAmounts(fn (Money $m): Money => $m->minus($amount)->minZero());
    }

    public function equals(self $other): bool
    {
        if (count($this->bands) !== count($other->bands)) {
            return false;
        }
        foreach ($this->bands as $i => $band) {
            if ($band['fromAge'] !== $other->bands[$i]['fromAge'] || ! $band['amount']->equals($other->bands[$i]['amount'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(Money): Money  $fn
     */
    private function mapAmounts(callable $fn): self
    {
        return new self(array_map(
            fn (array $band): array => ['fromAge' => $band['fromAge'], 'amount' => $fn($band['amount'])],
            $this->bands,
        ));
    }
}
