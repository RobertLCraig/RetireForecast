<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benchmark;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The PLSA (Pensions and Lifetime Savings Association) Retirement Living Standards:
 * three illustrative annual budgets — Minimum, Moderate, Comfortable — for a single
 * person and for a couple, with a separate higher cut for London. They give a household
 * a recognised yardstick for "what does this level of spending buy in retirement".
 *
 * Used only as a factual benchmark: the engine reports which standard a given annual
 * spend reaches; it never says whether that is enough or what the household should do.
 *
 * IMPORTANT basis (PLSA's own definition, see {@see SOURCE}): the figures EXCLUDE rent
 * and mortgage (they assume the home is owned outright) but INCLUDE everyday home
 * running costs (energy, council tax, maintenance). So the spend compared against them
 * must likewise exclude rent/mortgage and include running costs — the app's results
 * presenter strips the housing leg before calling this. (The engine stays framework-free:
 * it never names the app layer.)
 *
 * Per the project rule "no magic numbers": every external figure carries its source
 * and the date it was verified. ⚠️ These were read from the PLSA site on
 * {@see VERIFIED_ON} via an automated fetch; re-confirm them against the published
 * table in the go-live figure-verification pass before relying on them publicly.
 */
final class RetirementLivingStandards
{
    /** The published source of the figures below. */
    public const SOURCE = 'https://www.retirementlivingstandards.org.uk/details';

    /** The edition the figures are taken from. */
    public const EDITION = '2025 update (research during 2025, modelling completed 2026)';

    /** The date the figures were read from {@see SOURCE}. */
    public const VERIFIED_ON = '2026-06-26';

    /** The three standards, in ascending order. */
    public const TIERS = ['minimum', 'moderate', 'comfortable'];

    /** Display labels for the tiers. */
    public const TIER_LABELS = [
        'minimum' => 'Minimum',
        'moderate' => 'Moderate',
        'comfortable' => 'Comfortable',
    ];

    /**
     * Annual expenditure in whole pounds, per tier, as
     * [single outside London, couple outside London, single in London, couple in London].
     * ⚠️ Verify against {@see SOURCE} (edition {@see EDITION}) before go-live.
     *
     * @var array<string, array{int, int, int, int}>
     */
    private const FIGURES = [
        'minimum' => [13_900, 22_500, 14_600, 24_100],
        'moderate' => [32_700, 45_400, 34_000, 47_000],
        'comfortable' => [45_400, 62_700, 47_200, 64_800],
    ];

    /** The annual budget for one standard, for the given household composition. */
    public static function tier(string $tier, bool $couple, bool $london): Money
    {
        if (! isset(self::FIGURES[$tier])) {
            throw new InvalidArgumentException("Unknown Retirement Living Standard tier: {$tier}.");
        }

        $index = ($couple ? 1 : 0) + ($london ? 2 : 0);

        return Money::fromPounds(self::FIGURES[$tier][$index]);
    }

    /** All three standards for the given household composition, keyed by {@see TIERS}. */
    public static function tiers(bool $couple, bool $london): RetirementLivingStandardsResult
    {
        $figures = [];
        foreach (self::TIERS as $tier) {
            $figures[$tier] = self::tier($tier, $couple, $london);
        }

        return new RetirementLivingStandardsResult($couple, $london, $figures);
    }

    /**
     * Classify an annual spend against the standards: returns the result carrying the
     * three tier figures and which standard the spend reaches (the highest tier whose
     * budget it meets, or null when it falls below the Minimum).
     */
    public static function classify(Money $annualSpend, bool $couple, bool $london): RetirementLivingStandardsResult
    {
        return self::tiers($couple, $london)->withSpend($annualSpend);
    }
}
