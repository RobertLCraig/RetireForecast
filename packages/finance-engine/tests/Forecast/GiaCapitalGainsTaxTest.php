<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A5: when a GIA holding is sold to fund spending, the pro-rata gain is realised and
 * taxed as CGT (after the annual exempt amount). A holding with no embedded gain pays
 * nothing — so the tax tracks the gain, not the disposal. This is the disposal tax the
 * forecast previously omitted; understating it flattered every unwrapped-asset plan.
 */
final class GiaCapitalGainsTaxTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A retired couple over State Pension age whose spend outruns their pensions, so they
     * must sell from p1's £300k GIA in the base year. $unrealisedGain sets the embedded gain.
     */
    private function household(Money $unrealisedGain): Household
    {
        return new Household(
            'Drawing the GIA',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(60_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            [new Account('p1', AccountType::Gia, Money::fromPounds(300_000), unrealisedGain: $unrealisedGain)],
        );
    }

    public function test_disposing_a_gainful_gia_incurs_cgt_where_a_no_gain_one_does_not(): void
    {
        // £200k of the £300k GIA is gain; the year-0 shortfall sells a slice and realises a
        // pro-rata gain, taxed as CGT after the £3k exempt amount.
        $withGain = $this->forecaster()->forecast($this->household(Money::fromPounds(200_000)), AssumptionSetLibrary::default(), $this->settings())->years[0];
        // Same balance, no embedded gain (basis == balance): selling realises nothing to tax.
        $noGain = $this->forecaster()->forecast($this->household(Money::zero()), AssumptionSetLibrary::default(), $this->settings())->years[0];

        $this->assertGreaterThan($noGain->totalTax->pence, $withGain->totalTax->pence, 'realised GIA gains must be taxed as CGT');
        // The difference is material CGT, not rounding: a ~£23k realised gain (less £3k AEA)
        // taxed at 18% basic is several thousand pounds.
        $this->assertGreaterThan(100_000, $withGain->totalTax->pence - $noGain->totalTax->pence);
    }

    public function test_the_personal_allowance_does_not_extend_the_lower_cgt_band_below_it(): void
    {
        // The band-straddle fix, tested to the penny. 2026-27 statutory figures: PA £12,570,
        // basic-rate band £37,700, CGT AEA £3,000, residential rates 18% / 24%.
        $pa = 1_257_000;
        $band = 3_770_000;
        $aea = 300_000;
        $basic = Percent::fromPercent(18);
        $higher = Percent::fromPercent(24);
        $gain = Money::fromPounds(50_000)->pence; // £50,000 realised gain → £47,000 chargeable

        // £0 other income: the PA is NOT available against gains, so only the £37,700 band fills
        // at 18% — £37,700 @ 18% (£6,786) + £9,300 @ 24% (£2,232) = £9,018. (The pre-fix bug let
        // the PA extend the 18% band, taxing all £47,000 at 18% = £8,460 — a £558 understatement.)
        $this->assertSame(Money::fromPounds(9_018)->pence, PathProjector::cgtOnGain($gain, 0, $aea, $pa, $band, $basic, $higher));

        // Income anywhere at or below the PA consumes none of the basic-rate band, so the CGT is
        // identical to the £0 case — under the bug the £0 case was cheaper, so this pins the fix.
        $this->assertSame(
            PathProjector::cgtOnGain($gain, 0, $aea, $pa, $band, $basic, $higher),
            PathProjector::cgtOnGain($gain, $pa, $aea, $pa, $band, $basic, $higher),
        );

        // Income £20,000 is £7,430 above the PA, shrinking the 18% room to £30,270: £30,270 @ 18%
        // (£5,448.60) + £16,730 @ 24% (£4,015.20) = £9,463.80.
        $this->assertSame(Money::of(9_463, 80)->pence, PathProjector::cgtOnGain($gain, Money::fromPounds(20_000)->pence, $aea, $pa, $band, $basic, $higher));
    }

    public function test_a_gainful_gia_depletes_faster_than_a_no_gain_one(): void
    {
        // Over the run the CGT drag means the gainful holding funds fewer years / less wealth.
        $withGain = $this->forecaster()->forecast($this->household(Money::fromPounds(200_000)), AssumptionSetLibrary::default(), $this->settings());
        $noGain = $this->forecaster()->forecast($this->household(Money::zero()), AssumptionSetLibrary::default(), $this->settings());

        $this->assertLessThanOrEqual($noGain->terminalTotalWealth->pence, $withGain->terminalTotalWealth->pence);
    }

    public function test_partial_gia_disposals_conserve_cost_basis_with_no_drift(): void
    {
        // £300k holding, £100k cost basis -> £200k embedded gain (all in pence). Sell it in
        // uneven slices: each slice's realised gain + basis consumed must equal the slice
        // exactly, and once fully sold the realised gains must sum to exactly the embedded
        // gain with the basis at zero — no round-of-sum drift across disposals.
        $balance = 30_000_000;
        $basis = 10_000_000;
        $embeddedGain = $balance - $basis;

        $totalGain = 0;
        foreach ([1_234_567, 5_000_000, 333_333, 9_999_999, 7_777_777] as $take) {
            [$gain, $basisConsumed] = PathProjector::disposeGiaSlice($balance, $basis, $take);
            $this->assertSame($take, $gain + $basisConsumed, 'gain + basis consumed must equal the disposal');
            $totalGain += $gain;
            $balance -= $take;
            $basis -= $basisConsumed;
        }

        // Sell whatever remains.
        [$gain, $basisConsumed] = PathProjector::disposeGiaSlice($balance, $basis, $balance);
        $totalGain += $gain;
        $basis -= $basisConsumed;

        $this->assertSame(0, $basis, 'cost basis is fully consumed once the holding is sold');
        $this->assertSame($embeddedGain, $totalGain, 'total realised gain equals the embedded gain — no drift');
    }
}
