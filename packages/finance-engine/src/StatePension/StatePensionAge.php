<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\StatePension;

use DateInterval;
use DateTimeImmutable;

/**
 * Computes a person's State Pension age from their date of birth alone.
 *
 * State Pension age must never be hard-coded: it is mid-transition. It is 66 for
 * those born before 6 April 1960, phases up one month at a time to 67 across the
 * 1960–61 cohort (the band our demo couple straddle), is 67 to early 1977, then
 * phases to 68 across the 1977–78 cohort.
 *
 * The boundaries use the tax-month convention (the 6th of one month to the 5th of
 * the next). This is a pure function of the date of birth: no clock is read, so a
 * given DOB always yields the same answer.
 *
 * ⚠️ The exact monthly boundary dates of both transitions should be confirmed
 * against gov.uk's "Check your State Pension age" before any figure is shown as
 * real. Cohorts before 6 April 1960 (already at or past pension age) are treated
 * as 66, which is outside this tool's forward-looking scope.
 */
final class StatePensionAge
{
    public static function for(DateTimeImmutable $dob): StatePensionAgeResult
    {
        $totalMonths = self::ageInMonths($dob);
        $years = intdiv($totalMonths, 12);
        $months = $totalMonths % 12;

        $dateReached = $dob->add(new DateInterval("P{$years}Y{$months}M"));

        return new StatePensionAgeResult($years, $months, $dateReached);
    }

    private static function ageInMonths(DateTimeImmutable $dob): int
    {
        $transitionTo67Start = new DateTimeImmutable('1960-04-06');
        $age67From = new DateTimeImmutable('1961-03-06');
        $transitionTo68Start = new DateTimeImmutable('1977-04-06');
        $age68From = new DateTimeImmutable('1978-04-06');

        return match (true) {
            $dob < $transitionTo67Start => 66 * 12,
            $dob < $age67From => 66 * 12 + self::monthsInto($dob, 1960, 4),
            $dob < $transitionTo68Start => 67 * 12,
            $dob < $age68From => 67 * 12 + self::monthsInto($dob, 1977, 4),
            default => 68 * 12,
        };
    }

    /**
     * How many one-month steps the date of birth sits into a transition window that
     * begins on the 6th of $baseMonth/$baseYear. The first tax month (6th to 5th)
     * counts as one step, matching the statutory tables where the earliest cohort
     * gains one month over the base age.
     */
    private static function monthsInto(DateTimeImmutable $dob, int $baseYear, int $baseMonth): int
    {
        $year = (int) $dob->format('Y');
        $month = (int) $dob->format('n');
        $day = (int) $dob->format('j');

        // Days before the 6th belong to the previous tax month.
        if ($day < 6) {
            $month--;
            if ($month === 0) {
                $month = 12;
                $year--;
            }
        }

        return ($year - $baseYear) * 12 + ($month - $baseMonth) + 1;
    }
}
