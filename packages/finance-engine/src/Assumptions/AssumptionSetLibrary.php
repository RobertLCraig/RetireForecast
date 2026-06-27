<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Assumptions;

use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The shipped, sourced AssumptionSets the forecast can run against. All return and
 * volatility figures are REAL (above-inflation), annual. Figures signed off
 * 2026-06-24; see docs/ASSUMPTIONS.md for sourcing and the judgement calls.
 *
 * $investmentIncomeYield (added 2026-06-27 for A5 — GIA income tax + CGT) is a NOMINAL
 * income yield, held uniform at 2.0% across the sets for v1 (a portfolio's income yield
 * is broadly regime-independent). A modelling assumption, not a statutory figure: it is
 * anchored to the global-equity dividend yield (~1.3-2%), reviewed 2026-06-27 and kept.
 *
 * Three asset classes in a fixed order — global equities, gilts/bonds, cash — so
 * the correlation matrices line up with {@see AssumptionSet::$assetClasses}.
 */
final class AssumptionSetLibrary
{
    /** The engine default: FCA-derived real returns + DMS volatilities/correlations. */
    public static function fcaDefault(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'FCA default (FCA returns + DMS volatilities)',
            sourceNote: 'Expected returns derived from FCA COBS 13 Annex 2 nominal rates '
                .'(deflated by 2% inflation); volatilities and correlations from the Barclays '
                .'Equity Gilt Study / Dimson-Marsh-Staunton long-run record. Real, annual.',
            assetClasses: [
                new AssetClassAssumption('Global equities', Percent::fromPercent(4.4), Percent::fromPercent(23)),
                new AssetClassAssumption('Gilts/bonds', Percent::fromPercent(0.0), Percent::fromPercent(13)),
                new AssetClassAssumption('Cash', Percent::fromPercent(-0.5), Percent::fromPercent(2)),
            ],
            correlationMatrix: [
                [1.0, 0.30, 0.10],
                [0.30, 1.0, 0.30],
                [0.10, 0.30, 1.0],
            ],
            inflationMean: Percent::fromPercent(2.0),
            inflationVolatility: Percent::fromPercent(1.5),
            houseGrowth: Percent::fromPercent(1.0),
            rentInflation: Percent::fromPercent(0.5),
            salaryGrowth: Percent::fromPercent(1.0),
            investmentIncomeYield: Percent::fromPercent(2.0),
            isDefault: true,
        );
    }

    /** Compare set: the full long-run historical record (DMS world / Barclays UK). */
    public static function dmsHistorical(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'DMS historical',
            sourceNote: 'Real returns, volatilities and correlations from the long-run '
                .'(1900-2024) Dimson-Marsh-Staunton / Barclays Equity Gilt Study record, '
                .'including high-inflation decades. Real, annual.',
            assetClasses: [
                new AssetClassAssumption('Global equities', Percent::fromPercent(5.2), Percent::fromPercent(23)),
                new AssetClassAssumption('Gilts/bonds', Percent::fromPercent(1.5), Percent::fromPercent(13)),
                new AssetClassAssumption('Cash', Percent::fromPercent(0.5), Percent::fromPercent(7.5)),
            ],
            correlationMatrix: [
                [1.0, 0.46, 0.10],
                [0.46, 1.0, 0.30],
                [0.10, 0.30, 1.0],
            ],
            inflationMean: Percent::fromPercent(3.0),
            inflationVolatility: Percent::fromPercent(4.0),
            houseGrowth: Percent::fromPercent(2.5),
            rentInflation: Percent::fromPercent(0.5),
            salaryGrowth: Percent::fromPercent(1.5),
            investmentIncomeYield: Percent::fromPercent(2.0),
        );
    }

    /** Compare set: assets as the FCA default, but inflation/housing anchored to OBR/BoE. */
    public static function obrBoeAnchored(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'OBR/BoE inflation-anchored',
            sourceNote: 'Asset returns/volatilities as the FCA default; inflation, salary and '
                .'housing anchored to OBR (March 2026) and the Bank of England 2% CPI target. '
                .'Real, annual.',
            assetClasses: [
                new AssetClassAssumption('Global equities', Percent::fromPercent(4.4), Percent::fromPercent(23)),
                new AssetClassAssumption('Gilts/bonds', Percent::fromPercent(0.0), Percent::fromPercent(13)),
                new AssetClassAssumption('Cash', Percent::fromPercent(-0.5), Percent::fromPercent(2)),
            ],
            correlationMatrix: [
                [1.0, 0.30, 0.10],
                [0.30, 1.0, 0.30],
                [0.10, 0.30, 1.0],
            ],
            inflationMean: Percent::fromPercent(2.0),
            inflationVolatility: Percent::fromPercent(1.0),
            houseGrowth: Percent::fromPercent(1.0),
            rentInflation: Percent::fromPercent(0.0),
            salaryGrowth: Percent::fromPercent(1.0),
            investmentIncomeYield: Percent::fromPercent(2.0),
        );
    }

    /** All shipped sets, default first. */
    public static function all(): array
    {
        return [self::fcaDefault(), self::dmsHistorical(), self::obrBoeAnchored()];
    }

    public static function default(): AssumptionSet
    {
        return self::fcaDefault();
    }
}
