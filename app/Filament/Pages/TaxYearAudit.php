<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A read-only audit of the engine's statutory tax-year configuration: every figure
 * the calculators use, grouped by domain, each group carrying its gov.uk source URL
 * and the date it was verified. Nothing here is editable; the config lives in code
 * (the single source of truth) so outputs stay auditable and reproducible.
 */
class TaxYearAudit extends Page
{
    protected string $view = 'filament.pages.tax-year-audit';

    protected static ?string $navigationLabel = 'Tax-year audit';

    protected static ?string $title = 'Tax-year configuration audit';

    /** The tax years the registry can build, newest first. */
    public const TAX_YEARS = ['2026-27', '2025-26'];

    /** @return array<int, array{taxYear: string, verifiedOn: string, groups: array}> */
    public function years(): array
    {
        return array_map(
            fn (string $taxYear): array => $this->describe(TaxYearRegistry::for($taxYear)),
            self::TAX_YEARS,
        );
    }

    private function describe(TaxYearConfig $c): array
    {
        return [
            'taxYear' => $c->taxYear,
            'verifiedOn' => $c->verifiedOn,
            'groups' => [
                $this->group('Income tax', $c->sources['income_tax'] ?? null, [
                    'Personal allowance' => $c->incomeTax->personalAllowance->format(),
                    'Basic-rate band' => $c->incomeTax->basicRateBand->format(),
                    'Additional-rate threshold' => $c->incomeTax->additionalRateThreshold->format(),
                    'Basic / higher / additional rate' => $this->rates($c->incomeTax->basicRate, $c->incomeTax->higherRate, $c->incomeTax->additionalRate),
                ]),
                $this->group('Dividends', $c->sources['dividends'] ?? null, [
                    'Allowance' => $c->dividends->allowance->format(),
                    'Ordinary / upper / additional rate' => $this->rates($c->dividends->ordinaryRate, $c->dividends->upperRate, $c->dividends->additionalRate),
                ]),
                $this->group('National Insurance', $c->sources['national_insurance'] ?? null, [
                    'Primary threshold' => $c->nationalInsurance->primaryThreshold->format(),
                    'Upper earnings limit' => $c->nationalInsurance->upperEarningsLimit->format(),
                    'Main / upper rate' => $this->rates($c->nationalInsurance->mainRate, $c->nationalInsurance->upperRate),
                ]),
                $this->group('Pension allowances', $c->sources['pension'] ?? null, [
                    'Lump Sum Allowance' => $c->pension->lumpSumAllowance->format(),
                    'Annual Allowance' => $c->pension->annualAllowance->format(),
                    'Money Purchase AA' => $c->pension->moneyPurchaseAnnualAllowance->format(),
                    'PCLS rate' => $this->pct($c->pension->pclsRate),
                    'Normal minimum pension age' => (string) $c->pension->normalMinimumPensionAge,
                ]),
                $this->group('State Pension', $c->sources['state_pension'] ?? null, [
                    'New State Pension (weekly)' => $c->statePension->newStatePensionWeekly->format(),
                    'Basic State Pension (weekly)' => $c->statePension->basicStatePensionWeekly->format(),
                    'Full qualifying years' => (string) $c->statePension->fullQualifyingYears,
                ]),
                $this->group('CGT', $c->sources['cgt'] ?? null, [
                    'Annual exempt amount' => $c->cgt->annualExemptAmount->format(),
                    'Residential basic / higher rate' => $this->rates($c->cgt->residentialBasicRate, $c->cgt->residentialHigherRate),
                    'Final-period exemption (months)' => (string) $c->cgt->privateResidenceFinalExemptionMonths,
                ]),
                $this->group('Means-tested benefits', $c->sources['benefits'] ?? null, [
                    'Capital disregard' => $c->benefits->capitalDisregard->format(),
                    'Tariff step' => $c->benefits->tariffStep->format(),
                    'Housing-support upper limit' => $c->benefits->housingSupportUpperCapitalLimit->format(),
                ]),
                $this->group('Inheritance Tax', $c->sources['iht'] ?? null, [
                    'Nil-rate band' => $c->iht->nilRateBand->format(),
                    'Residence nil-rate band' => $c->iht->residenceNilRateBand->format(),
                    'Rate' => $this->pct($c->iht->rate),
                ]),
                $this->group('Care means-test', $c->sources['care'] ?? null, [
                    'Upper capital limit' => $c->care->upperCapitalLimit->format(),
                    'Lower capital limit' => $c->care->lowerCapitalLimit->format(),
                ]),
            ],
        ];
    }

    /** @param  array<string, string>  $figures */
    private function group(string $name, ?string $source, array $figures): array
    {
        return ['name' => $name, 'source' => $source, 'figures' => $figures];
    }

    private function rates(Percent ...$rates): string
    {
        return implode(' / ', array_map($this->pct(...), $rates));
    }

    private function pct(Percent $rate): string
    {
        return rtrim(rtrim(number_format($rate->asPercent(), 2), '0'), '.').'%';
    }
}
