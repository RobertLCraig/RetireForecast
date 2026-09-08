<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Mortality;

/**
 * How long the deterministic forecast plans for: a named percentile of the age at death
 * of the LAST surviving member of the household (board card 0061).
 *
 * The 50th is the median — a coin flip, with roughly even odds of living beyond it — which
 * is what the central path used to run to, and what makes "the money lasts for life" the
 * most misleading string this tool can print. The default is the 75th, the cautious end,
 * in line with the standing rule that a default which moves a result is the adverse one.
 *
 * Every case is a percentile of the DEATH-age distribution: at the 75th, about one household
 * in four still has somebody alive after the plan ends.
 */
enum PlanningHorizon: string
{
    case P50 = 'p50';
    case P75 = 'p75';
    case P90 = 'p90';

    /** The horizon in force where the reader has not chosen one. */
    public const DEFAULT = self::P75;

    /** The share of households whose last survivor has died by this horizon. */
    public function percentile(): float
    {
        return match ($this) {
            self::P50 => 0.50,
            self::P75 => 0.75,
            self::P90 => 0.90,
        };
    }

    /** The name of the setting, for a control the reader picks from. */
    public function label(): string
    {
        return match ($this) {
            self::P50 => 'Even odds (50th percentile)',
            self::P75 => 'Cautious (75th percentile)',
            self::P90 => 'Very cautious (90th percentile)',
        };
    }

    /**
     * What the horizon means in odds, in the reader's own words. This is the string that
     * has to sit beside any figure taken from the central path: a median lifespan is
     * roughly even odds, and must never be reported as a plan that lasts for life.
     */
    public function oddsPhrase(): string
    {
        return match ($this) {
            self::P50 => 'roughly even odds of one of you living longer',
            self::P75 => 'about one household in four still has somebody alive after it',
            self::P90 => 'about one household in ten still has somebody alive after it',
        };
    }
}
