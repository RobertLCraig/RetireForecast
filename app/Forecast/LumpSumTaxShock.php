<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\FlexibleWithdrawalAssessor;
use RetireForecast\FinanceEngine\Pension\FlexibleWithdrawalResult;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;

/**
 * Headline output #1: the pension lump-sum tax shock for a scenario's FIRST flexible
 * pension withdrawal. This is deterministic (no Monte Carlo) and independent of the
 * longevity simulation, so the results page can show it immediately.
 *
 * It orchestrates the engine's flagship {@see FlexibleWithdrawalAssessor} (the same
 * code that encodes HMRC worked example A to the penny) on the earliest UFPLS or
 * drawdown-income instruction in the household's DC pension plans, and presents the
 * 25%/75% split, the Month-1 emergency over-deduction and the reclaim form.
 *
 * Modelling note: the assessor needs the owner's *other* taxable income in the
 * withdrawal year. v1 assumes that is the owner's current salary if they are still
 * working at the withdrawal age, and £0 once they have retired (the common drawdown
 * case). The assumption is surfaced in the panel rather than hidden. State Pension and
 * DB income in payment are not yet layered in here (they are in the full forecast); the
 * over-deduction this panel leads with is driven by the emergency basis on the taxable
 * payment, which is independent of other income.
 */
final class LumpSumTaxShock
{
    public function __construct(private readonly ScenarioForecaster $forecaster = new ScenarioForecaster) {}

    /**
     * The shock for the scenario's first flexible withdrawal, ready for the view, or
     * null when no UFPLS/drawdown withdrawal is planned (a PCLS-only or empty plan).
     *
     * @return array<string, mixed>|null
     */
    public function assess(Scenario $scenario): ?array
    {
        $household = $scenario->household->toDto();

        $first = $this->firstFlexibleWithdrawal($household);
        if ($first === null) {
            return null;
        }
        [$pension, $instruction] = $first;

        $config = $this->forecaster->config($scenario);
        $owner = $household->person($pension->ownerId);
        $otherIncome = $this->otherIncome($owner, $instruction->atAge);
        $lsaRemaining = $config->pension->lumpSumAllowance->minus($pension->pclsTakenToDate ?? Money::zero())->minZero();
        $potEmptied = $instruction->amount->greaterThanOrEqual($pension->currentValue);

        $assessor = new FlexibleWithdrawalAssessor($config);
        $result = $instruction->kind === WithdrawalKind::Ufpls
            ? $assessor->assessUfpls($instruction->amount, $lsaRemaining, $otherIncome, $potEmptied)
            : $assessor->assessDrawdownIncome($instruction->amount, $otherIncome, $potEmptied);

        return $this->present($scenario, $household, $pension, $instruction, $otherIncome, $result);
    }

    /**
     * The earliest UFPLS or drawdown-income instruction across the DC pensions — the one
     * the emergency (Month-1) basis applies to. PCLS is skipped: it is tax-free cash with
     * no taxable element and no emergency-tax shock.
     *
     * @return array{0: DcPension, 1: WithdrawalInstruction}|null
     */
    private function firstFlexibleWithdrawal(Household $household): ?array
    {
        $best = null;
        foreach ($household->pensions as $pension) {
            if (! $pension instanceof DcPension) {
                continue;
            }
            foreach ($pension->withdrawalPlan as $instruction) {
                if (! in_array($instruction->kind, [WithdrawalKind::Ufpls, WithdrawalKind::DrawdownIncome], true)) {
                    continue;
                }
                if ($best === null || $instruction->atAge < $best[1]->atAge) {
                    $best = [$pension, $instruction];
                }
            }
        }

        return $best;
    }

    private function otherIncome(?Person $owner, int $atAge): TaxableIncome
    {
        if ($owner === null) {
            return TaxableIncome::ofNonSavings(Money::zero());
        }

        $stillWorking = in_array($owner->employmentStatus, [EmploymentStatus::Employed, EmploymentStatus::SelfEmployed], true)
            && ($owner->plannedRetirementAge === null || $atAge < $owner->plannedRetirementAge);

        $salary = $stillWorking && $owner->grossSalary !== null ? $owner->grossSalary : Money::zero();

        return TaxableIncome::ofNonSavings($salary);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        Scenario $scenario,
        Household $household,
        DcPension $pension,
        WithdrawalInstruction $instruction,
        TaxableIncome $otherIncome,
        FlexibleWithdrawalResult $r,
    ): array {
        return [
            'kind' => $instruction->kind === WithdrawalKind::Ufpls ? 'UFPLS (uncrystallised lump sum)' : 'drawdown income',
            'ownerLabel' => $this->ownerLabel($household, $pension->ownerId),
            'atAge' => $instruction->atAge,
            'taxYear' => $scenario->base_tax_year,
            'workingAssumed' => $otherIncome->total()->isPositive(),
            'otherIncome' => $otherIncome->total()->format(),
            'gross' => $r->gross->format(),
            'taxFree' => $r->taxFree->format(),
            'taxable' => $r->taxable->format(),
            'taxAtSource' => $r->taxDeductedAtSource->format(),
            'emergencyApplied' => $r->emergencyBasisApplied,
            'marginalTax' => $r->correctMarginalTax->format(),
            'overDeduction' => $r->overDeduction->format(),
            'hasOverDeduction' => $r->overDeduction->isPositive(),
            'reclaimForm' => $r->reclaimForm?->value,
            'netReceived' => $r->netReceived->format(),
            'mpaaTriggered' => $r->mpaaTriggered,
            'warnings' => array_map(fn ($w) => $w->message, $r->warnings),
            // The accessible table the headline text is also drawn from.
            'rows' => [
                ['label' => 'Gross withdrawal', 'value' => $r->gross->format()],
                ['label' => 'Tax-free (up to 25%, within the Lump Sum Allowance)', 'value' => $r->taxFree->format()],
                ['label' => 'Taxable portion', 'value' => $r->taxable->format()],
                ['label' => 'Tax taken at source'.($r->emergencyBasisApplied ? ' (emergency Month-1 basis)' : ''), 'value' => $r->taxDeductedAtSource->format()],
                ['label' => 'Tax actually due at marginal rates', 'value' => $r->correctMarginalTax->format()],
                ['label' => 'Over-deducted now, reclaimable', 'value' => $r->overDeduction->format()],
                ['label' => 'Net cash received before any reclaim', 'value' => $r->netReceived->format()],
            ],
            // Raw pence for tests and any future export.
            'raw' => [
                'grossPence' => $r->gross->pence,
                'taxFreePence' => $r->taxFree->pence,
                'taxablePence' => $r->taxable->pence,
                'taxAtSourcePence' => $r->taxDeductedAtSource->pence,
                'marginalTaxPence' => $r->correctMarginalTax->pence,
                'overDeductionPence' => $r->overDeduction->pence,
                'netReceivedPence' => $r->netReceived->pence,
                'reclaimForm' => $r->reclaimForm?->value,
                'mpaaTriggered' => $r->mpaaTriggered,
            ],
        ];
    }

    private function ownerLabel(Household $household, string $ownerId): string
    {
        if (count($household->persons) <= 1) {
            return 'the pension holder';
        }

        foreach ($household->persons as $i => $person) {
            if ($person->id === $ownerId) {
                return 'Person '.($i + 1);
            }
        }

        return 'the pension holder';
    }
}
