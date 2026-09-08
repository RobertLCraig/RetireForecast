<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * What a lifetime annuity pays, per £1 of purchase price, on the three things a real quote is
 * actually priced on: the age the income starts, whether it escalates with prices, and how much
 * of it carries on to a survivor. This is the ONE home of that pricing, so the builder cannot
 * offer a rate the engine would not recognise, and nothing else restates a figure from it.
 *
 * Board card 0065. Before it the rate was a single free-text field coupled to nothing, defaulted
 * to a level joint-life quote: tick "rises with inflation" and the plan bought an index-linked
 * annuity at a level annuity's rate, which is roughly HALF as much income as the market pays, in
 * the flattering direction, with nothing on the screen saying so. The same field stood while the
 * purchase age moved by fifteen years, which moves a real quote by more than half again.
 *
 * A reader with a real quote still enters it: this prices the sub-form's DEFAULT, which is what a
 * plan built without a quote falls back on, and every figure it produces is visible in the rate
 * field the reader can overwrite (the no-invisible-figures rule).
 *
 * SHAPE. A base single-life level rate by age, linearly interpolated between the anchor ages and
 * clamped outside them, times two multiplicative adjustments. Multiplicative because that is how
 * the market quotes the same options across the age range: the cost of escalation and of a
 * survivor's pension is roughly proportional to the income being bought, not a fixed number of
 * basis points. The survivor adjustment is linear in the FRACTION continuing, so a 100% joint
 * annuity costs twice what a 50% one does, which is the standing shape of a quote sheet.
 *
 * NOT PRICED, deliberately: a guarantee period, value protection, the SPOUSE'S age (a much
 * younger survivor is dearer), the buyer's postcode, and the market moving with gilt yields. Each
 * of them makes a real quote LOWER than this table where it applies, so a reader who has one of
 * them and does not enter their own quote is flattered. That is why the sub-form says to use a
 * real quote, and why {@see AnnuityPurchase::$rate} remains the reader's own input.
 *
 * SOURCING. The anchor rates and both adjustments are the standing shape of the UK open-market
 * option as the published comparison tables have carried it for years. They are STATED, NOT
 * VERIFIED against a live quote service: the unattended build loop that added them had no web
 * access. See docs/spec/ASSUMPTIONS.md §36 and the board card raised there to pin them against a
 * dated market snapshot. {@see VERIFIED_ON} is the date they were stated, not a fresh check.
 */
final class AnnuityRateTable
{
    /**
     * Single-life, LEVEL, standard-health annual income per £1 of purchase price, in basis
     * points, at each anchor age. It climbs with age because the insurer expects to pay it for
     * fewer years and because the mortality cross-subsidy grows.
     */
    public const LEVEL_SINGLE_LIFE_BPS = [
        55 => 560,
        60 => 620,
        65 => 710,
        70 => 810,
        75 => 960,
        80 => 1160,
        85 => 1400,
    ];

    /**
     * What an RPI/CPI-linked annuity pays at outset as a fraction of the level rate. An escalating
     * annuity starts far lower and takes well over a decade to catch up in cash terms, which is
     * exactly the trade this tool exists to show; quoting it at the level rate handed the plan an
     * inflation-proofed income for a flat income's price.
     */
    public const INDEX_LINKED_MULTIPLE = 0.62;

    /**
     * How much of the income a joint-life annuity gives up when the WHOLE of it continues to a
     * survivor. Scaled linearly by the survivor's fraction, so the common 50% option gives up half
     * of this. It is a reduction, so a bigger figure is the cautious direction.
     */
    public const FULL_SURVIVOR_REDUCTION = 0.20;

    /**
     * The age a quote is priced at when the reader has not yet said when they want the income. It
     * is the age the published comparison tables lead with, and the age the sub-form's own help
     * text describes, so the field opens on a figure this table stands behind.
     */
    public const DEFAULT_QUOTE_AGE = 65;

    /** The date the figures above were stated. Not a fresh market check: see the class docblock. */
    public const VERIFIED_ON = '2026-09-08';

    /** The one citation every figure in this table carries, so no caller restates it. */
    public const SOURCE = 'Standing shape of the UK open-market option as carried by the published '
        .'annuity comparison tables (Money Helper annuity comparison tool): '
        .'https://www.moneyhelper.org.uk/en/pensions-and-retirement/taking-your-pension/compare-annuities';

    /**
     * The rate an annuity of this shape is quoted at.
     *
     * @param  int  $incomeStartAge  the age the INCOME starts, which is what an insurer prices on:
     *                               for a deferred annuity that is later than the purchase age
     * @param  bool  $indexLinked  does the income rise with prices?
     * @param  float|null  $survivorFraction  the fraction continuing to a survivor (0.5 = the
     *                                        common half-pension option); null = single life
     */
    public static function quote(int $incomeStartAge, bool $indexLinked, ?float $survivorFraction): Percent
    {
        $rate = self::levelSingleLifeBps($incomeStartAge);

        if ($indexLinked) {
            $rate *= self::INDEX_LINKED_MULTIPLE;
        }

        $continuing = max(0.0, min(1.0, $survivorFraction ?? 0.0));
        $rate *= 1.0 - (self::FULL_SURVIVOR_REDUCTION * $continuing);

        // Round to a tenth of a percent: the precision a quote is published at, and enough that
        // the form field the reader sees and the figure the engine uses are the same number.
        return Percent::fromBasisPoints((int) round($rate / 10) * 10);
    }

    /**
     * The base rate at an age: the anchor where there is one, a straight line between the two
     * nearest anchors in between, and the nearest anchor outside the table. Clamping rather than
     * extrapolating is deliberate: below 55 no pension annuity can be bought at all, and above 85
     * the curve steepens in a way a straight line would understate, which is the safe direction.
     */
    private static function levelSingleLifeBps(int $age): float
    {
        $ages = array_keys(self::LEVEL_SINGLE_LIFE_BPS);
        $lowest = $ages[0];
        $highest = $ages[count($ages) - 1];

        if ($age <= $lowest) {
            return (float) self::LEVEL_SINGLE_LIFE_BPS[$lowest];
        }
        if ($age >= $highest) {
            return (float) self::LEVEL_SINGLE_LIFE_BPS[$highest];
        }

        $previous = $lowest;
        foreach ($ages as $anchor) {
            if ($anchor === $age) {
                return (float) self::LEVEL_SINGLE_LIFE_BPS[$anchor];
            }
            if ($anchor > $age) {
                $span = $anchor - $previous;
                $weight = ($age - $previous) / $span;

                return self::LEVEL_SINGLE_LIFE_BPS[$previous]
                    + $weight * (self::LEVEL_SINGLE_LIFE_BPS[$anchor] - self::LEVEL_SINGLE_LIFE_BPS[$previous]);
            }
            $previous = $anchor;
        }

        return (float) self::LEVEL_SINGLE_LIFE_BPS[$highest];
    }
}
