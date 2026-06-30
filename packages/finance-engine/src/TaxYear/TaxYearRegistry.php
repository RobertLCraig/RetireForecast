<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RuntimeException;

/**
 * Builds the {@see TaxYearConfig} for a given tax year and region.
 *
 * Every figure below was re-verified against gov.uk on 2026-06-27 (the go-live
 * figure-verification pass); the per-helper docblocks record the specific
 * confirmations. The earlier 2026-06-24 sign-off stands for the figures unchanged
 * since. Where a region is not yet supported the registry throws rather than
 * substituting wrong bands.
 */
final class TaxYearRegistry
{
    /** The tax years the registry has a configuration for — the single source of the set. */
    public const SUPPORTED_TAX_YEARS = ['2025-26', '2026-27'];

    public static function for(string $taxYear, RegionProfile $region = RegionProfile::EnglandWalesNi): TaxYearConfig
    {
        if ($region === RegionProfile::Scotland) {
            throw new RuntimeException(
                'Scottish income-tax bands are not loaded yet. Refusing to fall back to '
                .'rest-of-UK bands, which would silently produce wrong tax.'
            );
        }

        return match ($taxYear) {
            '2025-26' => self::englandWalesNi2025_26(),
            '2026-27' => self::englandWalesNi2026_27(),
            default => throw new InvalidArgumentException("No tax-year configuration for '{$taxYear}'."),
        };
    }

    private static function englandWalesNi2025_26(): TaxYearConfig
    {
        return new TaxYearConfig(
            taxYear: '2025-26',
            region: RegionProfile::EnglandWalesNi,
            incomeTax: new IncomeTaxParameters(
                personalAllowance: Money::fromPounds(12_570),
                taperThreshold: Money::fromPounds(100_000),
                taperRate: Percent::fromPercent(50),
                basicRateBand: Money::fromPounds(37_700),
                additionalRateThreshold: Money::fromPounds(125_140),
                basicRate: Percent::fromPercent(20),
                higherRate: Percent::fromPercent(40),
                additionalRate: Percent::fromPercent(45),
            ),
            dividends: new DividendParameters(
                allowance: Money::fromPounds(500),
                ordinaryRate: Percent::fromPercent(8.75),
                upperRate: Percent::fromPercent(33.75),
                additionalRate: Percent::fromPercent(39.35),
            ),
            savings: new SavingsParameters(
                psaBasicRate: Money::fromPounds(1_000),
                psaHigherRate: Money::fromPounds(500),
                psaAdditionalRate: Money::zero(),
                startingRateBand: Money::fromPounds(5_000),
                startingRate: Percent::zero(),
            ),
            nationalInsurance: new NationalInsuranceParameters(
                primaryThreshold: Money::fromPounds(12_570),
                upperEarningsLimit: Money::fromPounds(50_270),
                mainRate: Percent::fromPercent(8),
                upperRate: Percent::fromPercent(2),
            ),
            pension: self::pensionParameters(),
            statePension: new StatePensionParameters(
                newStatePensionWeekly: Money::of(230, 25),
                basicStatePensionWeekly: Money::of(176, 45),
                fullQualifyingYears: 35,
                minimumQualifyingYears: 10,
                weeksPerYear: 52,
                deferralWeeksPerUpliftStep: 9,
                deferralUpliftPerStep: Percent::fromPercent(1),
            ),
            sdlt: self::sdltParameters(),
            cgt: self::cgtParameters(),
            // Pension Credit Guarantee Credit 2025/26 (gov.uk Benefit and pension rates 2025-26).
            benefits: self::benefitsParameters(
                standardMinimumGuaranteeSingleWeekly: Money::of(227, 10),
                standardMinimumGuaranteeCoupleWeekly: Money::of(346, 60),
                severeDisabilityAdditionWeekly: Money::of(82, 90),
                carerAdditionWeekly: Money::of(46, 40),
            ),
            iht: self::ihtParameters(),
            care: self::careParameters(),
            sources: [
                'income_tax' => 'https://www.gov.uk/income-tax-rates',
                'dividends' => 'https://www.gov.uk/tax-on-dividends',
                'savings' => 'https://www.gov.uk/apply-tax-free-interest-on-savings',
                'national_insurance' => 'https://www.gov.uk/national-insurance-rates-letters',
                'pension' => 'https://www.gov.uk/tax-on-your-private-pension/annual-allowance',
                'state_pension' => 'https://www.gov.uk/new-state-pension/what-youll-get',
                'sdlt' => 'https://www.gov.uk/stamp-duty-land-tax/residential-property-rates',
                'cgt' => 'https://www.gov.uk/capital-gains-tax/rates',
                'benefits' => 'https://www.gov.uk/pension-credit/eligibility',
                'iht' => 'https://www.gov.uk/inheritance-tax',
                'care' => 'https://www.gov.uk/help-with-care-costs/financial-assessment',
            ],
            verifiedOn: '2026-06-27',
        );
    }

    private static function englandWalesNi2026_27(): TaxYearConfig
    {
        // Income-tax thresholds are frozen (now to April 2031), so they match 2025/26.
        // Dividend rates rise this year, which is the substantive difference.
        return new TaxYearConfig(
            taxYear: '2026-27',
            region: RegionProfile::EnglandWalesNi,
            incomeTax: new IncomeTaxParameters(
                personalAllowance: Money::fromPounds(12_570),
                taperThreshold: Money::fromPounds(100_000),
                taperRate: Percent::fromPercent(50),
                basicRateBand: Money::fromPounds(37_700),
                additionalRateThreshold: Money::fromPounds(125_140),
                basicRate: Percent::fromPercent(20),
                higherRate: Percent::fromPercent(40),
                additionalRate: Percent::fromPercent(45),
            ),
            dividends: new DividendParameters(
                allowance: Money::fromPounds(500),
                ordinaryRate: Percent::fromPercent(10.75),
                upperRate: Percent::fromPercent(35.75),
                additionalRate: Percent::fromPercent(39.35),
            ),
            savings: new SavingsParameters(
                psaBasicRate: Money::fromPounds(1_000),
                psaHigherRate: Money::fromPounds(500),
                psaAdditionalRate: Money::zero(),
                startingRateBand: Money::fromPounds(5_000),
                startingRate: Percent::zero(),
            ),
            nationalInsurance: new NationalInsuranceParameters(
                primaryThreshold: Money::fromPounds(12_570),
                upperEarningsLimit: Money::fromPounds(50_270),
                mainRate: Percent::fromPercent(8),
                upperRate: Percent::fromPercent(2),
            ),
            pension: self::pensionParameters(),
            statePension: new StatePensionParameters(
                // Triple lock uprating of +4.8% applied to the 2025/26 weekly rates.
                newStatePensionWeekly: Money::of(241, 30),
                basicStatePensionWeekly: Money::of(184, 90),
                fullQualifyingYears: 35,
                minimumQualifyingYears: 10,
                weeksPerYear: 52,
                deferralWeeksPerUpliftStep: 9,
                deferralUpliftPerStep: Percent::fromPercent(1),
            ),
            sdlt: self::sdltParameters(),
            cgt: self::cgtParameters(),
            // Pension Credit Guarantee Credit 2026/27 (gov.uk Benefit and pension rates 2026-27).
            benefits: self::benefitsParameters(
                standardMinimumGuaranteeSingleWeekly: Money::of(238, 0),
                standardMinimumGuaranteeCoupleWeekly: Money::of(363, 25),
                severeDisabilityAdditionWeekly: Money::of(86, 5),
                carerAdditionWeekly: Money::of(48, 15),
            ),
            iht: self::ihtParameters(),
            care: self::careParameters(),
            sources: [
                'income_tax' => 'https://commonslibrary.parliament.uk/research-briefings/cbp-10618/',
                'dividends' => 'https://commonslibrary.parliament.uk/research-briefings/cbp-10618/',
                'savings' => 'https://www.gov.uk/apply-tax-free-interest-on-savings',
                'national_insurance' => 'https://www.gov.uk/national-insurance-rates-letters',
                'pension' => 'https://www.gov.uk/tax-on-your-private-pension/annual-allowance',
                'state_pension' => 'https://www.gov.uk/new-state-pension/what-youll-get',
                'sdlt' => 'https://www.gov.uk/stamp-duty-land-tax/residential-property-rates',
                'cgt' => 'https://www.gov.uk/capital-gains-tax/rates',
                'benefits' => 'https://www.gov.uk/pension-credit/eligibility',
                'iht' => 'https://www.gov.uk/inheritance-tax',
                'care' => 'https://www.gov.uk/help-with-care-costs/financial-assessment',
            ],
            verifiedOn: '2026-06-27',
        );
    }

    /**
     * Pension allowances and limits. Frozen since 6 April 2024, so the same object
     * serves both tax years. Verified against gov.uk on 2026-06-27: LSA £268,275 and
     * LSDBA £1,073,100 (gov.uk/tax-on-your-private-pension/lump-sum-allowance); AA
     * £60,000, MPAA £10,000, tapered-AA adjusted-income £260,000 / threshold-income
     * £200,000 / £10,000 floor; minimum pension age 55, rising to 57 on 6 April 2028
     * (HMRC "Increasing Normal Minimum Pension Age", effect on and after 6 April 2028).
     */
    private static function pensionParameters(): PensionParameters
    {
        return new PensionParameters(
            lumpSumAllowance: Money::fromPounds(268_275),
            lumpSumAndDeathBenefitAllowance: Money::fromPounds(1_073_100),
            annualAllowance: Money::fromPounds(60_000),
            moneyPurchaseAnnualAllowance: Money::fromPounds(10_000),
            taperedAaAdjustedIncomeThreshold: Money::fromPounds(260_000),
            taperedAaThresholdIncomeLimit: Money::fromPounds(200_000),
            taperedAaMinimum: Money::fromPounds(10_000),
            taperRate: Percent::fromPercent(50),
            pclsRate: Percent::fromPercent(25),
            normalMinimumPensionAge: 55,
        );
    }

    /**
     * Residential SDLT bands for England and Northern Ireland, in force from 1 April
     * 2025, with the 5% additional-property surcharge (from 31 October 2024). The
     * same bands serve both tax years modelled here.
     */
    private static function sdltParameters(): SdltParameters
    {
        return new SdltParameters(
            bands: [
                new SdltBand(Money::fromPounds(0), Percent::zero()),
                new SdltBand(Money::fromPounds(125_000), Percent::fromPercent(2)),
                new SdltBand(Money::fromPounds(250_000), Percent::fromPercent(5)),
                new SdltBand(Money::fromPounds(925_000), Percent::fromPercent(10)),
                new SdltBand(Money::fromPounds(1_500_000), Percent::fromPercent(12)),
            ],
            additionalPropertySurchargeRate: Percent::fromPercent(5),
        );
    }

    /**
     * Residential CGT parameters. Verified against gov.uk/capital-gains-tax/rates on
     * 2026-06-27: residential gains are 18% within the basic-rate band and 24% above
     * it; the annual exempt amount is £3,000; the final 9 months of ownership always
     * qualify for Private Residence Relief (HS283).
     */
    private static function cgtParameters(): CgtParameters
    {
        return new CgtParameters(
            annualExemptAmount: Money::fromPounds(3_000),
            residentialBasicRate: Percent::fromPercent(18),
            residentialHigherRate: Percent::fromPercent(24),
            privateResidenceFinalExemptionMonths: 9,
        );
    }

    /**
     * Pension-age means-tested benefit parameters. The capital rules are static (the same
     * for both tax years); the Guarantee Credit figures (the appropriate minimum guarantee
     * + the severe-disability / carer additions) are uprated each year and passed in.
     * Verified against gov.uk on 2026-06-27 (capital rules: £10,000 disregarded, £1/week
     * per £500 above, £16,000 cap for Housing Benefit / Council Tax Support) and 2026-06-30
     * (Guarantee Credit figures — see the per-year call sites + {@see BenefitsParameters}).
     */
    private static function benefitsParameters(
        Money $standardMinimumGuaranteeSingleWeekly,
        Money $standardMinimumGuaranteeCoupleWeekly,
        Money $severeDisabilityAdditionWeekly,
        Money $carerAdditionWeekly,
    ): BenefitsParameters {
        return new BenefitsParameters(
            capitalDisregard: Money::fromPounds(10_000),
            tariffStep: Money::fromPounds(500),
            tariffIncomePerStepWeekly: Money::fromPounds(1),
            housingSupportUpperCapitalLimit: Money::fromPounds(16_000),
            standardMinimumGuaranteeSingleWeekly: $standardMinimumGuaranteeSingleWeekly,
            standardMinimumGuaranteeCoupleWeekly: $standardMinimumGuaranteeCoupleWeekly,
            severeDisabilityAdditionWeekly: $severeDisabilityAdditionWeekly,
            carerAdditionWeekly: $carerAdditionWeekly,
        );
    }

    /**
     * Inheritance Tax thresholds and rate. Frozen, so shared across both tax years.
     * Verified against gov.uk on 2026-06-27: NRB £325,000 (frozen to 5 April 2031),
     * RNRB £175,000 (frozen to 5 April 2030), tapered away £1 for every £2 of estate
     * above £2,000,000, standard rate 40%. The April 2027 pensions-in-estate change is
     * now enacted (Finance Act 2026, Royal Assent 18 March 2026, for deaths on or after
     * 6 April 2027) and stays behind the toggle.
     */
    private static function ihtParameters(): IhtParameters
    {
        return new IhtParameters(
            nilRateBand: Money::fromPounds(325_000),
            residenceNilRateBand: Money::fromPounds(175_000),
            residenceNilRateBandTaperThreshold: Money::fromPounds(2_000_000),
            taperRate: Percent::fromPercent(50),
            rate: Percent::fromPercent(40),
        );
    }

    /**
     * Adult social care means-test capital thresholds (England). Verified on 2026-06-27
     * (gov.uk + DHSC charging-for-care guidance 2025-26): upper £23,250, lower £14,250,
     * and £1 a week per £250 of capital between them, all frozen. The £86,000 lifetime
     * care cap was cancelled in July 2024 and is deliberately not modelled.
     */
    private static function careParameters(): CareParameters
    {
        return new CareParameters(
            upperCapitalLimit: Money::fromPounds(23_250),
            lowerCapitalLimit: Money::fromPounds(14_250),
            tariffStep: Money::fromPounds(250),
            tariffIncomePerStepWeekly: Money::fromPounds(1),
        );
    }
}
