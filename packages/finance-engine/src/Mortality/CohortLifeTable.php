<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Mortality;

use RetireForecast\FinanceEngine\Dto\Sex;

/**
 * UK mortality rates q(x) from the embedded ONS 2024-based tables, exposing both
 * the raw period rates and the cohort curve a living person actually experiences.
 *
 * The cohort curve is the diagonal of the period grid: someone aged x in year y
 * faces period q(x, y), then q(x+1, y+1), and so on, which embeds future mortality
 * improvement. Outside the published grid the table degrades gracefully and says so:
 *  - ages below 50 or years before 2025 clamp to the nearest cell (the tool targets
 *    older people; younger ages are out of scope);
 *  - years beyond 2074 hold the last published year (improvement frozen);
 *  - ages above 100 use a geometric Gompertz-style tail up to a hard cap of 110,
 *    where survival ends. These extrapolations are NOT ONS data.
 */
final class CohortLifeTable
{
    public const MAX_AGE = 110;

    /** @var array{male: array<int, array<int, float>>, female: array<int, array<int, float>>} */
    private readonly array $data;

    /**
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}|null  $data
     */
    public function __construct(?array $data = null)
    {
        $this->data = $data ?? OnsPeriodMortalityData::periodQx();
    }

    /**
     * The period probability that a person of $sex aged $age in $calendarYear dies
     * before their next birthday, clamped to the published grid.
     */
    public function periodQx(Sex $sex, int $age, int $calendarYear): float
    {
        $lookupAge = max(OnsPeriodMortalityData::FIRST_AGE, min($age, OnsPeriodMortalityData::LAST_AGE));
        $lookupYear = max(OnsPeriodMortalityData::FIRST_YEAR, min($calendarYear, OnsPeriodMortalityData::LAST_YEAR));

        return $this->data[$sex->value][$lookupAge][$lookupYear];
    }

    /**
     * The cohort q(x) curve for a person of $sex aged $currentAge in $baseYear:
     * an array [age => q(x)] from $currentAge to the hard cap age (110), built along
     * the period diagonal with a Gompertz-style tail above age 100.
     *
     * @return array<int, float>
     */
    public function cohortCurve(Sex $sex, int $currentAge, int $baseYear): array
    {
        $curve = [];

        for ($age = $currentAge; $age <= self::MAX_AGE; $age++) {
            $year = $baseYear + ($age - $currentAge);

            $curve[$age] = $age <= OnsPeriodMortalityData::LAST_AGE
                ? $this->periodQx($sex, $age, $year)
                : $this->tailQx($sex, $age, $currentAge, $baseYear);
        }

        // Hard cap: anyone reaching the top age does not survive beyond it.
        $curve[self::MAX_AGE] = 1.0;

        return $curve;
    }

    /**
     * The age by which cumulative survival first falls below 50% — the median age at
     * death, used as the single representative lifespan for a deterministic forecast.
     */
    public function medianDeathAge(Sex $sex, int $currentAge, int $baseYear): int
    {
        $survival = 1.0;
        foreach ($this->cohortCurve($sex, $currentAge, $baseYear) as $age => $qx) {
            $survival *= (1.0 - $qx);
            if ($survival < 0.5) {
                return $age;
            }
        }

        return self::MAX_AGE;
    }

    /**
     * Curtate life expectancy (expected remaining whole years of life) at the given
     * age, for sanity checks and the expected-lifespan death age.
     */
    public function lifeExpectancy(Sex $sex, int $currentAge, int $baseYear): float
    {
        $survival = 1.0;
        $expectancy = 0.0;
        foreach ($this->cohortCurve($sex, $currentAge, $baseYear) as $qx) {
            $survival *= (1.0 - $qx);
            $expectancy += $survival;
        }

        return $expectancy;
    }

    /**
     * Geometric extrapolation above the published age 100, using the local gradient
     * q(100)/q(99) (bounded), capped just below certainty. Not ONS data.
     */
    private function tailQx(Sex $sex, int $age, int $currentAge, int $baseYear): float
    {
        $yearAt100 = $baseYear + (100 - $currentAge);
        $q100 = $this->periodQx($sex, 100, $yearAt100);
        $q99 = $this->periodQx($sex, 99, $yearAt100 - 1);

        $ratio = $q99 > 0.0 ? $q100 / $q99 : 1.1;
        $ratio = max(1.05, min($ratio, 1.30));

        return min(0.99, $q100 * $ratio ** ($age - 100));
    }
}
