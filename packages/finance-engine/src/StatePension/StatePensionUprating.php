<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\StatePension;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * How the State Pension is uprated each year, for the whole length of a projection.
 *
 * The triple lock raises the State Pension by the HIGHEST of average earnings growth, CPI, or
 * 2.5%. This engine models the last two limbs ({@see TRIPLE_LOCK_FLOOR_BPS}); the earnings limb
 * is not modelled, because the only earnings series the engine carries is the household's own
 * real salary growth, which is an assumption about one couple's pay and not about national
 * average weekly earnings. Leaving it out understates the State Pension, so the omission errs
 * in the cautious direction — but it is an omission, and the disclosure says so.
 *
 * Until board card 0038 the floor was a bare `max($infl, 0.025)` in the projector: no source, no
 * assumption field, no control and nothing on any screen. With inflation modelled near 2% the
 * floor binds in most years, so the State Pension grew in REAL terms for the whole plan, and the
 * Pension Credit guarantee (uprated by the same running factor) rose with it. Assuming a
 * contested policy survives four decades is the OPTIMISTIC branch, and it was being chosen
 * silently, which is what the choice below exists to undo.
 *
 * SOURCE: the triple lock is the higher of average weekly earnings growth, CPI inflation and
 * 2.5%. The earnings limb is statutory (Social Security Administration Act 1992 s.150A, which
 * requires the Secretary of State to uprate the new and basic State Pension at least in line with
 * earnings); the CPI and 2.5% limbs are Government policy, re-confirmed at each fiscal event and
 * committed to for the 2024 Parliament. The 2.5% is the policy's own named parameter rather than
 * an estimated series, so it is not a judgement figure — but it was NOT re-fetched from gov.uk
 * when this was written, because an unattended session has no web access. See
 * docs/spec/ASSUMPTIONS.md §19.
 */
enum StatePensionUprating: string
{
    /** The triple lock holds for the whole projection: the engine's default, and the optimistic branch. */
    case TripleLock = 'triple_lock';

    /** The triple lock holds to a stated year, and the State Pension rises with prices alone after it. */
    case TripleLockUntil = 'triple_lock_until';

    /** No floor at all: the State Pension rises with prices, which is the adverse branch. */
    case Inflation = 'inflation';

    /**
     * The lowest annual rise the triple lock guarantees, in basis points. Read this constant
     * wherever the figure is shown; restating it is how a disclosure comes to name a number the
     * projection is not using.
     */
    public const TRIPLE_LOCK_FLOOR_BPS = 250;

    /** The floor as a rate, for anything that has to show it. */
    public static function floor(): Percent
    {
        return Percent::fromBasisPoints(self::TRIPLE_LOCK_FLOOR_BPS);
    }

    /**
     * The nominal rise the State Pension takes into $calendarYear, as a fraction. $untilYear is
     * the last year the floor applies under {@see TripleLockUntil} and is ignored by the other
     * two; a TripleLockUntil with no year is the same as prices alone, because a lock with no end
     * date that was asked to have one has nothing to lock to.
     */
    public function increase(float $inflation, int $calendarYear, ?int $untilYear = null): float
    {
        return $this->floorApplies($calendarYear, $untilYear)
            ? max($inflation, self::floor()->asFraction())
            : $inflation;
    }

    /** Does the 2.5% floor still apply in $calendarYear? */
    public function floorApplies(int $calendarYear, ?int $untilYear = null): bool
    {
        return match ($this) {
            self::TripleLock => true,
            self::TripleLockUntil => $untilYear !== null && $calendarYear <= $untilYear,
            self::Inflation => false,
        };
    }
}
