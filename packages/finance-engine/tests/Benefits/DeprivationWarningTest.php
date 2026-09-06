<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Benefits;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0049: a plan that moves a large sum must SAY that the money can be treated as still
 * held, for means-tested benefits (the notional capital rule) and for care charging (deliberate
 * deprivation), and must point the reader at a benefits check before the money moves.
 *
 * The trigger is the events the model already knows about — a pension lump sum or withdrawal, a
 * capital receipt, a one-off cost (which is how a gift out is entered), a home sale — and never a
 * calculation, because both rules turn on motive and foreseeability that no model holds.
 */
final class DeprivationWarningTest extends TestCase
{
    private function flat(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * @param  list<CapitalReceipt>  $receipts
     * @param  list<array{atAge: int, amount: Money, label: string}>  $oneOffs
     * @param  list<DcPension>  $pensions
     */
    private function household(array $receipts = [], array $oneOffs = [], array $pensions = []): Household
    {
        $profile = new ExpenseProfile(Money::fromPounds(19_000), Money::zero(), Percent::fromPercent(100));
        foreach ($oneOffs as $cost) {
            $profile = $profile->withOneOffCost($cost['atAge'], $cost['amount'], $cost['label']);
        }

        return new Household(
            'Deprivation',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            $profile,
            pensions: $pensions,
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(27_000), true, false, 60)],
            capitalReceipts: $receipts,
        );
    }

    private function forecast(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** @return list<string> */
    private function deprivationMessages(ForecastResult $forecast): array
    {
        $out = [];
        foreach ($forecast->years as $year) {
            foreach ($year->warnings as $warning) {
                if ($warning->code === WarningCode::CAPITAL_DEPRIVATION) {
                    $out[] = $warning->message;
                }
            }
        }

        return $out;
    }

    public function test_a_large_capital_receipt_is_warned_about_for_benefits_and_for_care(): void
    {
        $messages = $this->deprivationMessages($this->forecast($this->household(
            receipts: [new CapitalReceipt('p1', 'Family gift', Money::fromPounds(60_000), 2028)],
        )));

        $this->assertNotSame([], $messages, 'a £60,000 capital receipt raises a deprivation warning');
        $this->assertStringContainsString('notional capital', $messages[0], 'the benefits rule is named');
        $this->assertStringContainsString('deliberate deprivation', $messages[0], 'the care-charging rule is named');
        $this->assertStringContainsString('receiving', $messages[0], 'the move that triggered it is named');
    }

    public function test_a_large_one_off_cost_is_warned_about(): void
    {
        // A gift OUT is entered as a one-off cost (modelling gifts as such is card 0059), so this
        // is the shape the reader's "help the children with a deposit" plan actually takes.
        $messages = $this->deprivationMessages($this->forecast($this->household(
            oneOffs: [['atAge' => 70, 'amount' => Money::fromPounds(40_000), 'label' => 'Deposit for our daughter']],
        )));

        $this->assertNotSame([], $messages);
        $this->assertStringContainsString('Deposit for our daughter', $messages[0]);
    }

    public function test_a_large_pension_withdrawal_is_warned_about(): void
    {
        $pension = new DcPension(
            ownerId: 'p1',
            currentValue: Money::fromPounds(300_000),
            ongoingContribution: Money::zero(),
            employerContribution: Money::zero(),
            earliestAccessAge: 55,
            withdrawalPlan: [new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(80_000), 70)],
        );

        $messages = $this->deprivationMessages($this->forecast($this->household(pensions: [$pension])));

        $this->assertNotSame([], $messages, 'an £80,000 pot withdrawal raises a deprivation warning');
    }

    public function test_a_plan_that_moves_nothing_large_is_not_warned(): void
    {
        // The household lives on its income and moves no capital: a warning here would be noise
        // on every plan, which is how a warning stops being read.
        $this->assertSame([], $this->deprivationMessages($this->forecast($this->household())));
    }

    public function test_a_small_move_is_not_warned(): void
    {
        $this->assertSame([], $this->deprivationMessages($this->forecast($this->household(
            receipts: [new CapitalReceipt('p1', 'Small gift', Money::fromPounds(2_000), 2028)],
        ))));
    }

    public function test_the_warning_points_the_reader_at_a_benefits_check_before_they_move_the_money(): void
    {
        $messages = $this->deprivationMessages($this->forecast($this->household(
            receipts: [new CapitalReceipt('p1', 'Family gift', Money::fromPounds(60_000), 2028)],
        )));

        $this->assertNotSame([], $messages);
        $this->assertStringContainsString('benefits check before you move the money', $messages[0]);
    }
}
