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
 * $houseGrowthVolatility + $houseEquityCorrelation (added 2026-07-18) make house-price
 * growth stochastic in the Monte Carlo. REAL UK house-price volatility ~9% a year (about
 * half of equities' ~20%), and a LOW positive house-equity correlation (~0.2): both from
 * the long-run record (Jordà-Knoll-Kuvshinov-Schularick-Taylor, "The Rate of Return on
 * Everything, 1870-2015", NBER w24112 — housing far less volatile than equities, with low
 * equity-housing covariance / real diversification gains). See docs/ASSUMPTIONS.md.
 *
 * $salaryGrowthVolatility + $salaryEquityCorrelation (added 2026-07-18) do the same for
 * REAL salary growth. Aggregate real earnings growth is far smoother than markets (its
 * volatility ~half that of GDP growth per the SF Fed, ~2% a year in the UK record; ~2.5%
 * over the more volatile long run) and near-acyclical once workforce composition nets out,
 * so a deliberately LOW ~0.1 salary-equity correlation (weaker than housing's). See
 * docs/ASSUMPTIONS.md.
 *
 * $careCostRealGrowth (added 2026-07-18) escalates self-funder care fees at CPI + 2% real
 * across all sets — care is ~60-75% National-Living-Wage-pinned staff cost, which government
 * ratchets deliberately above prices, and PSSRU/LSE + OBR long-term social-care projections
 * escalate care unit costs on earnings/productivity (~2% real above CPI). The most adverse of
 * the defensible standing range (1.5-3% real); user-editable, with the sourcing + judgement in
 * docs/ASSUMPTIONS.md.
 *
 * $investmentCharge (added 2026-07-31) is the annual ongoing charge on invested balances —
 * platform/administration fee plus fund OCF — held uniform at 0.50% across the sets, because
 * a charge is a price, not a market regime. The asset-class returns above are GROSS of
 * charges (the FCA COBS 13 basis deducts charges separately), so without it the portfolio is
 * modelled as held for free. 0.50% sits just above the central case: UK workplace DC default
 * arrangements averaged 0.48% member-borne (DWP Pension Charges Survey 2020) with a median
 * AMC of 0.28% on providers' largest default funds (DWP Pension Provider Survey 2024/25),
 * against a 0.75% statutory charge cap that does NOT bind in decumulation; retail DIY runs
 * ~0.30-0.60% all-in (platform 0.15-0.35% + tracker OCF ~0.15-0.25%). Deliberately NOT the
 * top of the range: the charge falls on invested wealth, so it moves the sell-and-invest
 * plans against the stay-put ones, which makes an over-adverse figure a thumb on the scale of
 * the comparison rather than a safe margin. User-editable; sourcing in docs/spec/ASSUMPTIONS.md.
 *
 * Three asset classes in a fixed order — global equities, gilts/bonds, cash — so
 * the correlation matrices line up with {@see AssumptionSet::$assetClasses}; the house
 * factor correlates to index 0 (global equities).
 */
final class AssumptionSetLibrary
{
    /**
     * The sourcing carried by every shipped asset class (board card 0062). Held as constants
     * rather than repeated per class so one home owns each citation, and so a re-source moves
     * every set that reads it. The returns and the volatilities are cited SEPARATELY because
     * they come from different places; see {@see AssetClassAssumption}.
     *
     * The URLs are the ones docs/spec/ASSUMPTIONS.md already carries, not fetched here: the
     * session that added them had no web access. The verified-on date is the sign-off date this
     * file's own docblock records, not a fresh check. Re-verifying both, and re-sourcing the gilt
     * real return against current index-linked gilt yields, is board card 0137.
     */
    public const FCA_RETURN_SOURCE = 'FCA COBS 13 Annex 2 standardised projection rates (intermediate), '
        .'deflated by the 2% CPI assumption: https://handbook.fca.org.uk/handbook/COBS/13/Annex2.html';

    public const DMS_RETURN_SOURCE = 'Dimson-Marsh-Staunton long-run (1900-2024) realised real returns, via the '
        .'UBS Global Investment Returns Yearbook and the Barclays Equity Gilt Study: '
        .'https://www.ubs.com/global/en/investment-bank/insights-and-data/2025/global-investment-returns-yearbook-2025.html';

    public const DMS_VOLATILITY_SOURCE = 'Dimson-Marsh-Staunton long-run (1900-2024) annual standard deviations '
        .'of real returns, via the UBS Global Investment Returns Yearbook and the Barclays Equity Gilt Study: '
        .'https://www.ubs.com/global/en/investment-bank/insights-and-data/2025/global-investment-returns-yearbook-2025.html';

    /** The date the figures below were signed off. Not a fresh check: see the sourcing note above. */
    public const VERIFIED_ON = '2026-06-24';

    /** The engine default: FCA-derived real returns + DMS volatilities/correlations. */
    public static function fcaDefault(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'FCA default (FCA returns + DMS volatilities)',
            sourceNote: 'Expected returns derived from FCA COBS 13 Annex 2 nominal rates '
                .'(deflated by 2% inflation); volatilities and correlations from the Barclays '
                .'Equity Gilt Study / Dimson-Marsh-Staunton long-run record. Real, annual.',
            assetClasses: [
                self::fcaSourced('Global equities', 4.4, 23),
                self::fcaSourced('Gilts/bonds', 0.0, 13),
                self::fcaSourced('Cash', -0.5, 2),
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
            houseGrowthVolatility: Percent::fromPercent(9.0),
            houseEquityCorrelation: 0.2,
            salaryGrowthVolatility: Percent::fromPercent(2.0),
            salaryEquityCorrelation: 0.1,
            careCostRealGrowth: Percent::fromPercent(2.0),
            investmentCharge: Percent::fromPercent(0.5),
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
                self::dmsSourced('Global equities', 5.2, 23),
                self::dmsSourced('Gilts/bonds', 1.5, 13),
                self::dmsSourced('Cash', 0.5, 7.5),
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
            // The long-run record spans the volatile mid-century + 1970s-2000s housing cycles,
            // so a wider house-price spread than the forward-looking sets.
            houseGrowthVolatility: Percent::fromPercent(11.0),
            houseEquityCorrelation: 0.2,
            // Wider salary spread over the long historical run (the volatile 1970s-80s real-wage swings).
            salaryGrowthVolatility: Percent::fromPercent(2.5),
            salaryEquityCorrelation: 0.1,
            careCostRealGrowth: Percent::fromPercent(2.0),
            investmentCharge: Percent::fromPercent(0.5),
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
                self::fcaSourced('Global equities', 4.4, 23),
                self::fcaSourced('Gilts/bonds', 0.0, 13),
                self::fcaSourced('Cash', -0.5, 2),
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
            houseGrowthVolatility: Percent::fromPercent(9.0),
            houseEquityCorrelation: 0.2,
            salaryGrowthVolatility: Percent::fromPercent(2.0),
            salaryEquityCorrelation: 0.1,
            careCostRealGrowth: Percent::fromPercent(2.0),
            investmentCharge: Percent::fromPercent(0.5),
        );
    }

    /**
     * An asset class on the FCA basis: the return from the FCA's projection rates, the
     * volatility from the long-run record. Both figures carry their own citation, so no
     * shipped class can reach a projection unsourced.
     */
    private static function fcaSourced(string $name, float $realReturn, float $volatility): AssetClassAssumption
    {
        return new AssetClassAssumption(
            $name,
            Percent::fromPercent($realReturn),
            Percent::fromPercent($volatility),
            self::FCA_RETURN_SOURCE,
            self::VERIFIED_ON,
            self::DMS_VOLATILITY_SOURCE,
            self::VERIFIED_ON,
        );
    }

    /** An asset class on the long-run historical basis: both figures from the same record. */
    private static function dmsSourced(string $name, float $realReturn, float $volatility): AssetClassAssumption
    {
        return new AssetClassAssumption(
            $name,
            Percent::fromPercent($realReturn),
            Percent::fromPercent($volatility),
            self::DMS_RETURN_SOURCE,
            self::VERIFIED_ON,
            self::DMS_VOLATILITY_SOURCE,
            self::VERIFIED_ON,
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
