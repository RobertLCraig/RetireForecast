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
 * Figures verified against official sources on 2026-06-24. Items still needing a
 * confirmatory gov.uk citation before any figure is shown as real are tracked in
 * the project plan's "build-time verification checklist". Where a region is not
 * yet supported the registry throws rather than substituting wrong bands.
 */
final class TaxYearRegistry
{
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
            benefits: self::benefitsParameters(),
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
            verifiedOn: '2026-06-24',
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
            benefits: self::benefitsParameters(),
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
            verifiedOn: '2026-06-24',
        );
    }

    /**
     * Pension allowances and limits. Frozen since 6 April 2024, so the same object
     * serves both tax years. ⚠️ The tapered-AA thresholds, the Lump Sum & Death
     * Benefit Allowance and the 6 April 2028 rise of the minimum pension age to 57
     * still need a confirmatory gov.uk citation before any figure is shown as real.
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
     * Residential CGT parameters. ⚠️ The 18%/24% residential rates, the £3,000
     * annual exempt amount and the 9-month final-period exemption all need a
     * confirmatory gov.uk citation before being shown as real.
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
     * Pension-age means-tested benefit capital rules. These figures have been static
     * for years; the same object serves both tax years modelled here. ⚠️ Confirm
     * against gov.uk before being shown as real.
     */
    private static function benefitsParameters(): BenefitsParameters
    {
        return new BenefitsParameters(
            capitalDisregard: Money::fromPounds(10_000),
            tariffStep: Money::fromPounds(500),
            tariffIncomePerStepWeekly: Money::fromPounds(1),
            housingSupportUpperCapitalLimit: Money::fromPounds(16_000),
        );
    }

    /**
     * Inheritance Tax thresholds and rate. Frozen, so shared across both tax years.
     * ⚠️ Confirm against gov.uk, and re-verify the April 2027 pensions-in-estate
     * change before relying on the toggle.
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
     * Adult social care means-test capital thresholds (England). ⚠️ Confirm against
     * gov.uk; the £86,000 care cap is deliberately not modelled.
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
