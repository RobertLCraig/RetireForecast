<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Assumptions;

use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\FigureSource;
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

    /**
     * The citations the ECONOMIC assumptions carry, one constant per source so a re-source moves
     * every set that reads it (board card 0065). Before this they shared one prose `sourceNote` per
     * set, with nothing per figure and no date any command could read, while the statutory figures
     * beside them had carried both from the start. These move the answer far more than the
     * statutory ones do.
     *
     * Every URL here is one docs/spec/ASSUMPTIONS.md already carries. None was fetched: the
     * unattended build loop has no web access, so the verified-on dates below are the dates each
     * figure was signed off in this repository, not fresh checks. `figures:freshness` is what turns
     * that into something that ages loudly.
     */
    public const OBR_MACRO_SOURCE = 'OBR Economic and Fiscal Outlook, March 2026, with the Bank of England '
        .'2% CPI target as the inflation anchor: https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/';

    public const ONS_HOUSING_SOURCE = 'ONS Private rent and house prices, UK (June 2026): '
        .'https://www.ons.gov.uk/economy/inflationandpriceindices/bulletins/privaterentandhousepricesuk/june2026';

    public const HOUSE_RISK_SOURCE = 'Jorda, Knoll, Kuvshinov, Schularick and Taylor, "The Rate of Return on '
        .'Everything, 1870-2015", NBER Working Paper 24112 (housing far less volatile than equities, low '
        .'equity-housing covariance): https://www.nber.org/papers/w24112';

    public const SALARY_RISK_SOURCE = 'Champagne, Kurmann and Stewart, "Dissecting Aggregate Real Wage '
        .'Fluctuations", FRB San Francisco WP 2011-23, sanity-checked against ONS Average weekly earnings: '
        .'https://www.frbsf.org/wp-content/uploads/wp11-23bk.pdf';

    public const CARE_COST_SOURCE = 'PSSRU/LSE (Wittenberg et al.), long-term care expenditure projections '
        .'(care unit costs escalated on earnings, about 2% real above prices): '
        .'https://eprints.lse.ac.uk/88376/1/Wittenberg_Adult%20Social%20Care_Published.pdf';

    public const INVESTMENT_CHARGE_SOURCE = 'DWP Pension Charges Survey 2020 (0.48% average member-borne '
        .'ongoing charge in qualifying default arrangements): '
        .'https://www.gov.uk/government/publications/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes';

    /**
     * The single-property volatility MULTIPLE is a reviewer's calibration, not a published series,
     * and the citation says so rather than dressing it up. The published record supports the
     * direction and the order of magnitude; the 2.0 itself is board card 0086.
     */
    public const SINGLE_PROPERTY_SOURCE = 'Direction and order of magnitude from the long-run housing-returns '
        .'record: https://www.nber.org/papers/w24112 . The multiple itself is the property reviewer\'s '
        .'calibration of 2026-08-19 and is NOT from a published series: board card 0086.';

    /** The date the single-property multiple was calibrated. See {@see SINGLE_PROPERTY_SOURCE}. */
    public const SINGLE_PROPERTY_VERIFIED_ON = '2026-08-19';

    /**
     * The inflation dynamics ({@see INFLATION_PERSISTENCE}, {@see INFLATION_ASSET_CORRELATIONS})
     * are STATED against the shape of the UK record, not fitted to a downloaded series. Board card
     * 0139 carries fitting them.
     */
    public const INFLATION_DYNAMICS_SOURCE = 'Stated against the shape of the UK CPI/RPI record (multi-year '
        .'episodes in 1973-75, 1979-81 and 2021-23), which is the ONS inflation and price indices series: '
        .'https://www.ons.gov.uk/economy/inflationandpriceindices . NOT fitted to a downloaded series: '
        .'board card 0139.';

    /** The date the inflation dynamics were stated. See {@see INFLATION_DYNAMICS_SOURCE}. */
    public const INFLATION_DYNAMICS_VERIFIED_ON = '2026-09-08';

    /**
     * How much of one year's deviation from mean inflation survives into the next (board card
     * 0064). UK annual CPI/RPI inflation is strongly autocorrelated over the long record — it
     * arrives in multi-year episodes (1973-75, 1979-81, 2021-23) rather than as independent
     * annual surprises — and a first-order coefficient around 0.7 is the standing range for
     * annual UK inflation in the empirical literature. It is applied at the CAUTIOUS end of what
     * it is for: it widens the cumulative price-level fan, which is adverse here, because the
     * model runs against nominal tax thresholds frozen for years.
     *
     * STATED, NOT VERIFIED: this session had no web access. See docs/spec/ASSUMPTIONS.md §35 and
     * the card raised there to source it against an ONS CPIH/RPI series.
     */
    public const INFLATION_PERSISTENCE = 0.70;

    /**
     * The correlation of the inflation shock with each asset class's REAL return, in the fixed
     * asset order below (global equities, gilts/bonds, cash). All NEGATIVE, and by different
     * amounts, which is the whole point: in a real-return framework an inflation shock is worst
     * for the asset whose cash flows are fixed in money. Nominal gilts take the full hit; cash
     * takes it too, because deposit rates lag prices; equities are partly real assets and are hit
     * less. Together these make a 2022 possible in the model — high inflation and deeply negative
     * real bond and equity returns in the same year — which independent draws could not produce.
     *
     * Kept short of the extreme end deliberately: an over-negative row would price the whole
     * portfolio as one bet on inflation and, past a point, describes a correlation structure that
     * cannot exist at all (the augmented matrix stops being positive-definite and the Cholesky
     * decomposition refuses it, loudly).
     *
     * STATED, NOT VERIFIED: this session had no web access. See docs/spec/ASSUMPTIONS.md §35.
     */
    public const INFLATION_ASSET_CORRELATIONS = [-0.30, -0.50, -0.55];

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
            inflationPersistence: self::INFLATION_PERSISTENCE,
            inflationAssetCorrelations: self::INFLATION_ASSET_CORRELATIONS,
            isDefault: true,
            economicSourcing: self::economicSourcing(self::OBR_MACRO_SOURCE),
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
            inflationPersistence: self::INFLATION_PERSISTENCE,
            inflationAssetCorrelations: self::INFLATION_ASSET_CORRELATIONS,
            // This set reads its macro figures off the same long-run record its asset figures come
            // from, not off a forward-looking forecast, so its macro citation is that record.
            economicSourcing: self::economicSourcing(self::DMS_RETURN_SOURCE),
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
            inflationPersistence: self::INFLATION_PERSISTENCE,
            inflationAssetCorrelations: self::INFLATION_ASSET_CORRELATIONS,
            economicSourcing: self::economicSourcing(self::OBR_MACRO_SOURCE),
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

    /**
     * The per-figure sourcing every shipped set carries (board card 0065). One builder rather than
     * three lists, because only the MACRO citation differs between the sets: a forward-looking set
     * anchors its inflation, house and salary means to the OBR, while the historical set reads them
     * off the same long-run record its asset figures come from. Everything else (the risk figures,
     * the care escalation, the charge) is the same source whichever set is chosen.
     *
     * The order and the KEYS are the AssumptionSet constructor's own property names, which is what
     * lets `EconomicAssumptionSourcingTest` enumerate that constructor and fail on a figure added
     * here without a citation.
     *
     * @return list<FigureSource>
     */
    private static function economicSourcing(string $macroSource): array
    {
        return [
            new FigureSource('correlationMatrix', 'Asset-class correlations', self::DMS_VOLATILITY_SOURCE, self::VERIFIED_ON),
            new FigureSource('inflationMean', 'Mean annual CPI inflation', $macroSource, self::VERIFIED_ON),
            new FigureSource('inflationVolatility', 'Annual inflation volatility', $macroSource, self::VERIFIED_ON),
            new FigureSource('houseGrowth', 'Real house-price growth', self::ONS_HOUSING_SOURCE, self::VERIFIED_ON),
            new FigureSource('rentInflation', 'Real rent growth', self::ONS_HOUSING_SOURCE, self::VERIFIED_ON),
            new FigureSource('salaryGrowth', 'Real salary growth', $macroSource, self::VERIFIED_ON),
            new FigureSource('investmentIncomeYield', 'Portfolio income yield', self::DMS_RETURN_SOURCE, self::VERIFIED_ON),
            new FigureSource('houseGrowthVolatility', 'House-price index volatility', self::HOUSE_RISK_SOURCE, self::VERIFIED_ON),
            new FigureSource('houseEquityCorrelation', 'House-price to equity correlation', self::HOUSE_RISK_SOURCE, self::VERIFIED_ON),
            new FigureSource('salaryGrowthVolatility', 'Real salary-growth volatility', self::SALARY_RISK_SOURCE, self::VERIFIED_ON),
            new FigureSource('salaryEquityCorrelation', 'Salary-growth to equity correlation', self::SALARY_RISK_SOURCE, self::VERIFIED_ON),
            new FigureSource('careCostRealGrowth', 'Real care-fee escalation', self::CARE_COST_SOURCE, self::VERIFIED_ON),
            new FigureSource('investmentCharge', 'Ongoing investment charge', self::INVESTMENT_CHARGE_SOURCE, self::VERIFIED_ON),
            new FigureSource('singlePropertyVolatility', 'One home\'s volatility over the index', self::SINGLE_PROPERTY_SOURCE, self::SINGLE_PROPERTY_VERIFIED_ON),
            new FigureSource('inflationPersistence', 'Inflation persistence', self::INFLATION_DYNAMICS_SOURCE, self::INFLATION_DYNAMICS_VERIFIED_ON),
            new FigureSource('inflationAssetCorrelations', 'Inflation to asset-class correlations', self::INFLATION_DYNAMICS_SOURCE, self::INFLATION_DYNAMICS_VERIFIED_ON),
        ];
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
