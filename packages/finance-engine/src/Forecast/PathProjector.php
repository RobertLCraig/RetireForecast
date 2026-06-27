<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;
use RetireForecast\FinanceEngine\StatePension\StatePensionCalculator;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\Tax\NationalInsuranceCalculator;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Projects one path of a household's finances year by year: assembles each person's
 * income, taxes it, meets the household's spend (drawing on assets per the chosen
 * strategy), grows what remains, and stops when the last survivor dies.
 *
 * Works in NOMINAL pounds internally so that frozen tax thresholds bite against
 * inflating incomes (fiscal drag), then deflates every figure to REAL today's money
 * for the result. Driven by {@see PathDraws}, so the deterministic forecast and the
 * Monte Carlo share this exact engine.
 *
 * Documented v1 scope (refinements deferred, all flagged for later):
 *  - Income tax covers non-savings income (earnings, pensions, State Pension,
 *    drawdown, taxable streams) plus, from A5, the annual income on unwrapped assets:
 *    cash interest (savings) and GIA dividends (dividend income), paid out and taxed
 *    each year while the asset grows at capital only. ISA stays tax-free. CGT on GIA
 *    disposals is realised pro-rata against cost basis (shared £3k AEA, 18/24% by band).
 *    v1: capital losses are not relieved; the CGT band is judged on non-savings income.
 *  - Tax thresholds are held frozen for the whole projection (slightly overstates
 *    fiscal drag after the 2031 freeze ends).
 *  - DB revaluation/escalation and the State Pension triple lock are modelled as
 *    smooth annual growth factors.
 */
final class PathProjector
{
    private readonly IncomeTaxCalculator $incomeTax;

    private readonly NationalInsuranceCalculator $ni;

    private readonly StatePensionCalculator $statePension;

    public function __construct(private readonly TaxYearConfig $config)
    {
        $this->incomeTax = new IncomeTaxCalculator($config);
        $this->ni = new NationalInsuranceCalculator($config);
        $this->statePension = new StatePensionCalculator($config);
    }

    public function project(Household $household, ForecastSettings $settings, PathDraws $draws): ForecastResult
    {
        $state = $this->initialState($household, $settings);

        $years = [];
        $cumInflation = 1.0; // product of (1+inflation) before the current year
        $depletionYear = null;

        for ($yearIndex = 0; ; $yearIndex++) {
            $calendarYear = $settings->baseYear + $yearIndex;

            $alive = [];
            foreach ($household->persons as $person) {
                $age = $state['baseAge'][$person->id] + $yearIndex;
                $alive[$person->id] = $age <= $draws->deathAge($person->id);
            }
            if (! in_array(true, $alive, true)) {
                break; // last survivor has died
            }

            $year = $this->projectYear($household, $settings, $draws, $state, $yearIndex, $calendarYear, $alive, $cumInflation);
            $years[] = $year;

            if ($depletionYear === null && ! $year->essentialsMet) {
                $depletionYear = $calendarYear;
            }

            // Advance growth factors and balances to the start of next year.
            $this->growState($state, $draws, $yearIndex);
            $cumInflation *= (1.0 + $draws->inflation($yearIndex));

            if ($yearIndex > 200) {
                break; // safety backstop; should never trigger (mortality caps at 110)
            }
        }

        $terminal = end($years) ?: null;

        return new ForecastResult(
            years: $years,
            essentialsAlwaysMet: $this->everyYear($years, fn (YearResult $y) => $y->essentialsMet),
            fullSpendAlwaysMet: $this->everyYear($years, fn (YearResult $y) => $y->fullSpendMet()),
            depletionCalendarYear: $depletionYear,
            terminalTotalWealth: $terminal ? $terminal->totalWealth : Money::zero(),
            terminalUsableWealth: $terminal ? $terminal->liquidWealth->plus($terminal->pensionWealth) : Money::zero(),
            finalCalendarYear: $terminal ? $terminal->calendarYear : $settings->baseYear,
        );
    }

    /**
     * Build the mutable per-person state (nominal balances, growth factors, base
     * ages, State Pension start years) for the run.
     *
     * @return array<string, mixed>
     */
    private function initialState(Household $household, ForecastSettings $settings): array
    {
        $baseAge = [];
        $spaYear = [];
        $cash = [];
        $gia = [];
        $giaBasis = [];
        $isa = [];
        $pots = [];
        $lsaUsed = [];

        foreach ($household->persons as $person) {
            $birthYear = (int) $person->dob->format('Y');
            $baseAge[$person->id] = $settings->baseYear - $birthYear;
            $spaYear[$person->id] = (int) StatePensionAge::for($person->dob)->dateReached->format('Y');
            $cash[$person->id] = 0;
            $gia[$person->id] = 0;
            $giaBasis[$person->id] = 0;
            $isa[$person->id] = 0;
            $pots[$person->id] = [];
            $lsaUsed[$person->id] = 0;
        }

        foreach ($household->accounts as $account) {
            $pid = $account->ownerId;
            match ($account->type) {
                AccountType::Cash, AccountType::PremiumBonds => $cash[$pid] += $account->balance->pence,
                AccountType::Gia => $gia[$pid] += $account->balance->pence,
                AccountType::Isa => $isa[$pid] += $account->balance->pence,
            };
            // GIA cost basis = balance minus the unrealised gain carried on the account,
            // so a later disposal taxes only the gain (CGT, A5). Other wrappers need no basis.
            if ($account->type === AccountType::Gia) {
                $giaBasis[$pid] += $account->balance->pence - ($account->unrealisedGain?->pence ?? 0);
            }
        }

        foreach ($household->pensions as $pension) {
            if ($pension instanceof DcPension) {
                $pots[$pension->ownerId][] = [
                    'value' => $pension->currentValue->pence,
                    'plan' => $pension->withdrawalPlan,
                    'firstAccessDone' => false,
                    'contribution' => $pension->ongoingContribution->pence + $pension->employerContribution->pence,
                ];
                $lsaUsed[$pension->ownerId] += $pension->pclsTakenToDate?->pence ?? 0;
            }
        }

        return [
            'baseYear' => $settings->baseYear,
            'baseAge' => $baseAge,
            'spaYear' => $spaYear,
            'cash' => $cash,
            'gia' => $gia,
            'giaBasis' => $giaBasis,
            'isa' => $isa,
            'pots' => $pots,
            'lsaUsed' => $lsaUsed,
            'property' => $household->primaryResidence?->currentValue->pence ?? 0,
            // Running nominal growth factors (1.0 in the base year).
            'salaryFactor' => 1.0,
            'dbFactor' => 1.0,
            'spFactor' => 1.0,
            'spendFactor' => 1.0,
            'rentFactor' => 1.0,
            'rentInflationReal' => $settings->rentInflationReal?->asFraction() ?? 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function projectYear(
        Household $household,
        ForecastSettings $settings,
        PathDraws $draws,
        array &$state,
        int $yearIndex,
        int $calendarYear,
        array $alive,
        float $cumInflation,
    ): YearResult {
        $ages = [];
        $taxablePerPerson = [];   // nominal non-savings taxable income
        $taxFreeCashNominal = 0;  // pension tax-free cash received this year
        $taxFreeIncomeNominal = 0; // tax-free income streams (e.g. DLA) received this year
        $grossIncomeNominal = 0;
        // Nominal income split by canonical source (YearResult::INCOME_SOURCES).
        $src = array_fill_keys(YearResult::INCOME_SOURCES, 0);

        foreach ($household->persons as $person) {
            $age = $state['baseAge'][$person->id] + $yearIndex;
            $ages[$person->id] = $age;
            $taxablePerPerson[$person->id] = 0;

            if (! $alive[$person->id]) {
                continue;
            }

            // Employment earnings (until planned retirement age).
            $earnings = 0;
            if ($person->employmentStatus === EmploymentStatus::Employed
                && $person->grossSalary !== null
                && ($person->plannedRetirementAge === null || $age < $person->plannedRetirementAge)) {
                $earnings = (int) round($person->grossSalary->pence * $state['salaryFactor']);
            }

            // Guaranteed pension / other income, kept split by source.
            $db = $this->dbIncome($household, $person->id, $age, $state['dbFactor']);
            $sp = $this->statePensionIncome($household, $person->id, $calendarYear, $state['spaYear'][$person->id], $state['spFactor']);
            $otherTaxable = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: true);
            $taxFreeStream = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: false);

            // Planned DC withdrawals due at this age.
            $wd = $this->plannedWithdrawals($state, $person->id, $age);

            $taxablePerPerson[$person->id] += $earnings + $db + $sp + $otherTaxable + $wd['taxable'];
            $taxFreeIncomeNominal += $taxFreeStream;
            $taxFreeCashNominal += $wd['taxFree'];

            $src['salary'] += $earnings;
            $src['defined_benefit'] += $db;
            $src['state_pension'] += $sp;
            $src['other_taxable'] += $otherTaxable;
            $src['tax_free_income'] += $taxFreeStream;
            $src['pension_lump_sum'] += $wd['taxFree'];
            $src['pension_drawdown'] += $wd['taxable'];
        }

        // Taxable investment income from unwrapped assets, on opening balances (A5):
        // GIA dividends and cash interest are paid out as income each year and taxed.
        // ISA is tax-free, so excluded. The rest of the return is capital growth, left in
        // the asset and taxed as CGT only on disposal — growState then grows GIA/cash at
        // capital only (these rates must mirror those there), so income paid out + capital
        // growth == total return, never double-counted.
        $infl = $draws->inflation($yearIndex);
        $cashInterestRate = max(0.0, (1.0 + $draws->cashRealReturn($yearIndex)) * (1.0 + $infl) - 1.0);
        $giaYield = $draws->investmentIncomeYield();

        // Tax each person individually; assemble household net cash. Tax-free income
        // streams (e.g. DLA) are added untaxed alongside pension tax-free cash.
        $netCashNominal = $taxFreeCashNominal + $taxFreeIncomeNominal;
        $totalTaxNominal = 0;
        foreach ($household->persons as $person) {
            if (! $alive[$person->id]) {
                continue;
            }
            $cashInterest = (int) round($state['cash'][$person->id] * $cashInterestRate);
            $giaDividends = (int) round($state['gia'][$person->id] * $giaYield);
            $investmentIncome = $cashInterest + $giaDividends;

            $taxable = $taxablePerPerson[$person->id];
            $grossIncomeNominal += $taxable + $investmentIncome;
            // Combined pass: non-savings, then cash interest (savings, with the PSA), then
            // GIA dividends (dividend allowance + rates) stacked on top.
            $tax = $this->incomeTax->compute(new TaxableIncome(
                Money::fromPence($taxable),
                Money::fromPence($cashInterest),
                Money::fromPence($giaDividends),
            ))->total->pence;
            $ni = $this->niForPerson($household, $person->id, $state, $yearIndex);
            $totalTaxNominal += $tax + $ni;
            $netCashNominal += $taxable + $investmentIncome - $tax - $ni;
            $src['investment_income'] += $investmentIncome;
        }
        $grossIncomeNominal += $taxFreeCashNominal + $taxFreeIncomeNominal;

        // Household spend (nominal), with the survivor factor when only one remains.
        $aliveCount = count(array_filter($alive));
        $survivor = $aliveCount === 1 ? $household->expenseProfile->survivorSpendFactor->asFraction() : 1.0;
        $spendNominal = (int) round($household->expenseProfile->targetAnnualSpend()->pence * $state['spendFactor'] * $survivor)
            + $this->oneOffCostsNominal($household, $ages, $cumInflation);
        $essentialNominal = (int) round($household->expenseProfile->essentialAnnualSpend->pence * $state['spendFactor'] * $survivor);

        // Rent (the "sell and rent" leg) is an essential cost with its own inflation.
        if ($settings->annualRent !== null) {
            $rentNominal = (int) round($settings->annualRent->pence * $state['rentFactor']);
            $spendNominal += $rentNominal;
            $essentialNominal += $rentNominal;
        }

        // Property running costs (maintenance, insurance, council tax) for owners are
        // essential too — the counterpart to a renter's rent.
        if ($household->primaryResidence?->runningCosts !== null) {
            $runningNominal = (int) round($household->primaryResidence->runningCosts->pence * $state['spendFactor']);
            $spendNominal += $runningNominal;
            $essentialNominal += $runningNominal;
        }

        // Fund any shortfall from assets per the drawdown strategy.
        $shortfall = $spendNominal - $netCashNominal;
        $fundedNominal = 0;
        if ($shortfall > 0) {
            $funded = $this->fundShortfall($household, $settings, $state, $alive, $taxablePerPerson, $shortfall);
            $fundedNominal = $funded['funded'];
            $totalTaxNominal += $funded['extraTax'];
            $src['pension_drawdown'] += $funded['fromPension'];
            $src['asset_drawdown'] += $funded['fromAssets'];
        } elseif ($shortfall < 0) {
            // Surplus first funds any planned contributions to long-term assets
            // (DC pension top-ups, regular account savings); what remains is saved
            // into the first living person's cash.
            $surplus = -$shortfall;
            $surplus -= $this->applyContributions($household, $state, $alive, $state['spendFactor'], $surplus);
            $surplusOwner = $this->firstLiving($household, $alive);
            if ($surplusOwner !== null && $surplus > 0) {
                $state['cash'][$surplusOwner] += $surplus;
            }
        }

        $metSpend = min($spendNominal, $netCashNominal + $fundedNominal);
        $unmetNominal = max(0, $spendNominal - $metSpend);
        $essentialsMet = $metSpend >= $essentialNominal;

        // Real (today's money) figures.
        $realFactor = 1.0 / $cumInflation;
        $r = fn (int $nominal): Money => Money::fromPence((int) round($nominal * $realFactor));

        $liquid = $this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']);
        $pension = $this->totalPots($state);

        // Round each wealth leg once, then derive the total from those rounded parts —
        // never round the raw sum independently, or total wealth drifts from liquid +
        // pension + property by a penny (round-of-sum != sum-of-rounds). Data-integrity
        // rule: a reported total has one definition, built from its components.
        $liquidReal = $r($liquid);
        $pensionReal = $r($pension);
        $propertyReal = $r($state['property']);

        return new YearResult(
            yearIndex: $yearIndex,
            calendarYear: $calendarYear,
            ages: $ages,
            aliveCount: $aliveCount,
            grossIncome: $r($grossIncomeNominal),
            totalTax: $r($totalTaxNominal),
            netIncome: $r($netCashNominal),
            spendTarget: $r($spendNominal),
            essentialSpend: $r($essentialNominal),
            shortfallFunded: $r($fundedNominal),
            unmetSpend: $r($unmetNominal),
            essentialsMet: $essentialsMet,
            liquidWealth: $liquidReal,
            pensionWealth: $pensionReal,
            propertyWealth: $propertyReal,
            totalWealth: $liquidReal->plus($pensionReal)->plus($propertyReal),
            incomeBySource: array_map($r, $src),
        );
    }

    private function dbIncome(Household $household, string $pid, int $age, float $dbFactor): int
    {
        $total = 0;
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DbPension && $pension->ownerId === $pid && $age >= $pension->normalRetirementAge) {
                $total += (int) round($pension->accruedAnnualPension->pence * $dbFactor);
            }
        }

        return $total;
    }

    private function statePensionIncome(Household $household, string $pid, int $calendarYear, int $spaYear, float $spFactor): int
    {
        if ($calendarYear < $spaYear) {
            return 0;
        }
        foreach ($household->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $pid) {
                $base = $pension->weeklyForecast !== null
                    ? $this->statePension->fromWeeklyForecast($pension->weeklyForecast, $pension->deferralWeeks)
                    : $this->statePension->fromQualifyingYears($pension->qualifyingYears ?? 0, $pension->deferralWeeks);

                return (int) round($base->annual->pence * $spFactor);
            }
        }

        return 0;
    }

    private function incomeStreamsNominal(Household $household, string $pid, int $age, float $cumInflation, bool $taxable): int
    {
        $total = 0;
        foreach ($household->incomeStreams as $stream) {
            if ($stream->ownerId !== $pid || $stream->taxable !== $taxable) {
                continue;
            }
            if ($age < $stream->startAge || ($stream->endAge !== null && $age > $stream->endAge)) {
                continue;
            }
            $total += $stream->inflationLinked
                ? (int) round($stream->grossAnnual->pence * $cumInflation)
                : $stream->grossAnnual->pence;
        }

        return $total;
    }

    /**
     * Execute any planned withdrawals from this person's pots due at $age, mutating
     * pot balances and LSA use. Returns the taxable and tax-free amounts (nominal).
     *
     * @param  array<string, mixed>  $state
     * @return array{taxable: int, taxFree: int}
     */
    private function plannedWithdrawals(array &$state, string $pid, int $age): array
    {
        $taxable = 0;
        $taxFree = 0;
        $lsaRemaining = $this->config->pension->lumpSumAllowance->pence - $state['lsaUsed'][$pid];
        $pclsRate = $this->config->pension->pclsRate->asFraction();

        foreach ($state['pots'][$pid] as &$pot) {
            foreach ($pot['plan'] as $instruction) {
                if (! $instruction instanceof WithdrawalInstruction || $instruction->atAge !== $age) {
                    continue;
                }
                $amount = min($instruction->amount->pence, $pot['value']);
                if ($amount <= 0) {
                    continue;
                }

                switch ($instruction->kind) {
                    case WithdrawalKind::Ufpls:
                        $tf = min((int) floor($amount * $pclsRate), max(0, $lsaRemaining));
                        $taxFree += $tf;
                        $taxable += $amount - $tf;
                        $pot['value'] -= $amount;
                        $state['lsaUsed'][$pid] += $tf;
                        $lsaRemaining -= $tf;
                        break;
                    case WithdrawalKind::Pcls:
                        // amount = tax-free cash taken; the rest stays invested.
                        $tf = min($amount, max(0, $lsaRemaining));
                        $taxFree += $tf;
                        $pot['value'] -= $tf;
                        $state['lsaUsed'][$pid] += $tf;
                        $lsaRemaining -= $tf;
                        break;
                    case WithdrawalKind::DrawdownIncome:
                        $taxable += $amount;
                        $pot['value'] -= $amount;
                        break;
                }
            }
        }

        return ['taxable' => $taxable, 'taxFree' => $taxFree];
    }

    /**
     * Class 1 NI on this person's employment earnings, or zero if not employed, past
     * planned retirement, or at/after State Pension age (NI ends at SPA).
     *
     * @param  array<string, mixed>  $state
     */
    private function niForPerson(Household $household, string $pid, array $state, int $yearIndex): int
    {
        $person = $household->person($pid);
        if ($person === null || $person->employmentStatus !== EmploymentStatus::Employed || $person->grossSalary === null) {
            return 0;
        }

        $age = $state['baseAge'][$pid] + $yearIndex;
        if ($person->plannedRetirementAge !== null && $age >= $person->plannedRetirementAge) {
            return 0;
        }

        $calendarYear = $state['baseYear'] + $yearIndex;
        $reachedSpa = $calendarYear >= $state['spaYear'][$pid];

        $earnings = (int) round($person->grossSalary->pence * $state['salaryFactor']);

        return $this->ni->onEmploymentEarnings(Money::fromPence($earnings), hasReachedStatePensionAge: $reachedSpa)->total->pence;
    }

    /**
     * Draw assets to cover $shortfall (nominal) in the strategy's order, grossing up
     * pension withdrawals for tax. Returns the net funded, any extra tax incurred,
     * and how much was drawn from pensions (gross) vs other assets — so the cashflow
     * ladder can show where the shortfall money came from.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $taxablePerPerson
     * @return array{funded: int, extraTax: int, fromPension: int, fromAssets: int}
     */
    private function fundShortfall(Household $household, ForecastSettings $settings, array &$state, array $alive, array $taxablePerPerson, int $shortfall): array
    {
        $remaining = $shortfall;
        $funded = 0;
        $extraTax = 0;
        $fromPension = 0; // gross pension withdrawn to meet the shortfall
        $fromAssets = 0;  // capital drawn from cash/GIA/ISA
        // GIA gains realised this year by disposals, per person (feeds CGT below).
        $realisedGain = array_fill_keys(array_map(fn ($p): string => $p->id, $household->persons), 0);

        $pensionFirst = $settings->drawdownStrategy === DrawdownStrategy::PensionAware;

        $drawNonPension = function () use (&$state, &$remaining, &$funded, &$fromAssets, &$realisedGain, $alive, $household): void {
            foreach (['cash', 'gia', 'isa'] as $bucket) {
                foreach ($household->persons as $person) {
                    if ($remaining <= 0) {
                        return;
                    }
                    if (! $alive[$person->id]) {
                        continue;
                    }
                    $take = min($remaining, $state[$bucket][$person->id]);
                    if ($take > 0) {
                        // Selling a GIA holding realises the pro-rata gain and consumes the
                        // matching slice of cost basis, so a later disposal is not taxed twice.
                        if ($bucket === 'gia') {
                            $balance = $state['gia'][$person->id];
                            $basis = $state['giaBasis'][$person->id];
                            $realisedGain[$person->id] += (int) round(max(0, $balance - $basis) * $take / $balance);
                            $state['giaBasis'][$person->id] -= (int) round($basis * $take / $balance);
                        }
                        $state[$bucket][$person->id] -= $take;
                        $remaining -= $take;
                        $funded += $take;
                        $fromAssets += $take;
                    }
                }
            }
        };

        $drawPension = function (bool $capToBasicRate) use (&$state, &$remaining, &$funded, &$extraTax, &$fromPension, $alive, $household, $taxablePerPerson): void {
            $params = $this->config->incomeTax;
            $basicLimit = $params->personalAllowance->pence + $params->basicRateBand->pence;

            foreach ($household->persons as $person) {
                if ($remaining <= 0) {
                    return;
                }
                if (! $alive[$person->id]) {
                    continue;
                }
                $alreadyTaxable = $taxablePerPerson[$person->id];
                foreach ($state['pots'][$person->id] as &$pot) {
                    if ($remaining <= 0 || $pot['value'] <= 0) {
                        continue;
                    }
                    $cap = $pot['value'];
                    if ($capToBasicRate) {
                        $cap = min($cap, max(0, $basicLimit - $alreadyTaxable));
                    }
                    if ($cap <= 0) {
                        continue;
                    }
                    $gross = $this->grossUpPension($remaining, $alreadyTaxable, $cap);
                    if ($gross <= 0) {
                        continue;
                    }
                    $taxDelta = $this->marginalTax($alreadyTaxable, $gross);
                    $net = $gross - $taxDelta;
                    $pot['value'] -= $gross;
                    $remaining -= $net;
                    $funded += $net;
                    $extraTax += $taxDelta;
                    $fromPension += $gross;
                    $alreadyTaxable += $gross;
                }
                unset($pot);
            }
        };

        if ($pensionFirst) {
            $drawPension(true);   // pension up to the basic-rate band first
            $drawNonPension();
            $drawPension(false);  // then any remaining pension
        } else {
            $drawNonPension();
            $drawPension(false);  // pension only as a last resort
        }

        // CGT on the GIA gains realised funding this year's spend (computed before any
        // further drawing, so the small extra gain from funding the tax itself is not
        // re-taxed — a bounded v1 simplification). It is a real cost, so draw a little
        // more to pay it; that funding is not spend, so it is taken back out of $funded.
        $cgt = $this->capitalGainsTax($realisedGain, $taxablePerPerson, $alive);
        if ($cgt > 0) {
            $extraTax += $cgt;
            $remaining = $cgt;
            $fundedBeforeCgt = $funded;
            $drawNonPension();
            $drawPension(false);
            $funded = $fundedBeforeCgt;
        }

        return ['funded' => $funded, 'extraTax' => $extraTax, 'fromPension' => $fromPension, 'fromAssets' => $fromAssets];
    }

    /**
     * Capital Gains Tax on the GIA gains realised this year, per person, after the shared
     * annual exempt amount. Gains stack on top of income: the basic-rate band left after the
     * person's income is taxed at the lower CGT rate, the rest at the higher rate. The
     * residential CGT rates are reused — since the October 2024 Budget they equal the rates
     * for gains on shares (18% / 24%). v1 simplifications (flagged): the band is judged on
     * non-savings income only, and capital losses are not relieved.
     *
     * @param  array<string, int>  $realisedGain  personId => gain realised (nominal pence)
     * @param  array<string, int>  $taxablePerPerson
     * @param  array<string, bool>  $alive
     */
    private function capitalGainsTax(array $realisedGain, array $taxablePerPerson, array $alive): int
    {
        $cgt = $this->config->cgt;
        $aea = $cgt->annualExemptAmount->pence;
        $basicLimit = $this->config->incomeTax->personalAllowance->pence + $this->config->incomeTax->basicRateBand->pence;

        $total = 0;
        foreach ($realisedGain as $pid => $gain) {
            if (! ($alive[$pid] ?? false)) {
                continue;
            }
            $chargeable = max(0, $gain - $aea);
            if ($chargeable <= 0) {
                continue;
            }
            $basicRoom = max(0, $basicLimit - ($taxablePerPerson[$pid] ?? 0));
            $atBasic = min($chargeable, $basicRoom);
            $atHigher = $chargeable - $atBasic;
            $total += Money::fromPence($atBasic)->applyRate($cgt->residentialBasicRate)->pence;
            $total += Money::fromPence($atHigher)->applyRate($cgt->residentialHigherRate)->pence;
        }

        return $total;
    }

    /**
     * Gross pension withdrawal whose after-tax value meets $netNeeded, given the
     * person's existing taxable income, capped at $maxGross. Iterates to convergence
     * (income tax is piecewise linear, so this is exact within a few rounds).
     */
    private function grossUpPension(int $netNeeded, int $existingTaxable, int $maxGross): int
    {
        $gross = $netNeeded;
        for ($i = 0; $i < 8; $i++) {
            $tax = $this->marginalTax($existingTaxable, $gross);
            $next = $netNeeded + $tax;
            if (abs($next - $gross) <= 1) {
                $gross = $next;
                break;
            }
            $gross = $next;
        }

        return min($gross, $maxGross);
    }

    private function marginalTax(int $existingTaxable, int $extra): int
    {
        $base = $this->incomeTax->compute(TaxableIncome::ofNonSavings(Money::fromPence($existingTaxable)))->total->pence;
        $with = $this->incomeTax->compute(TaxableIncome::ofNonSavings(Money::fromPence($existingTaxable + $extra)))->total->pence;

        return $with - $base;
    }

    private function oneOffCostsNominal(Household $household, array $ages, float $cumInflation): int
    {
        $referenceId = array_key_first($ages);
        $referenceAge = $ages[$referenceId] ?? null;
        $total = 0;
        foreach ($household->expenseProfile->oneOffCosts as $cost) {
            if ($referenceAge !== null && ($cost['atAge'] ?? null) === $referenceAge) {
                $total += (int) round($cost['amount']->pence * $cumInflation);
            }
        }

        return $total;
    }

    /**
     * Direct this year's surplus into any planned long-term contributions — DC
     * pension top-ups and regular savings into accounts — capped at the surplus
     * available, in declaration order. Amounts are in today's money, grown to
     * nominal by $spendFactor. Returns the total contributed (nominal pence).
     *
     * Funded from surplus only (never by drawing down other assets), so saving
     * stops automatically once income no longer covers spend. v1 simplification:
     * pension contributions are taken from net surplus and tax relief on them is
     * not modelled, which slightly understates the pre-retirement pot — flagged
     * for the trust pass.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function applyContributions(Household $household, array &$state, array $alive, float $spendFactor, int $surplus): int
    {
        $available = $surplus;
        $contributed = 0;

        $take = function (int $annualPence) use (&$available, &$contributed, $spendFactor): int {
            if ($annualPence <= 0 || $available <= 0) {
                return 0;
            }
            $give = min((int) round($annualPence * $spendFactor), $available);
            $available -= $give;
            $contributed += $give;

            return $give;
        };

        // DC pension contributions (employee + employer) into each living owner's pots.
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            foreach ($state['pots'][$person->id] as &$pot) {
                $pot['value'] += $take($pot['contribution'] ?? 0);
            }
            unset($pot);
        }

        // Regular savings into accounts, added to the matching liquid bucket.
        foreach ($household->accounts as $account) {
            if ($account->ongoingContributions === null || ! ($alive[$account->ownerId] ?? false)) {
                continue;
            }
            $bucket = match ($account->type) {
                AccountType::Cash, AccountType::PremiumBonds => 'cash',
                AccountType::Gia => 'gia',
                AccountType::Isa => 'isa',
            };
            $added = $take($account->ongoingContributions->pence);
            $state[$bucket][$account->ownerId] += $added;
            // New money into a GIA raises its cost basis, so only later growth is a gain.
            if ($account->type === AccountType::Gia) {
                $state['giaBasis'][$account->ownerId] += $added;
            }
        }

        return $contributed;
    }

    private function firstLiving(Household $household, array $alive): ?string
    {
        foreach ($household->persons as $person) {
            if ($alive[$person->id]) {
                return $person->id;
            }
        }

        return null;
    }

    /**
     * Grow nominal balances and income factors to the start of the next year.
     *
     * @param  array<string, mixed>  $state
     */
    private function growState(array &$state, PathDraws $draws, int $yearIndex): void
    {
        $infl = $draws->inflation($yearIndex);
        $investNominal = (1.0 + $draws->investmentRealReturn($yearIndex)) * (1.0 + $infl) - 1.0;
        $cashNominal = (1.0 + $draws->cashRealReturn($yearIndex)) * (1.0 + $infl) - 1.0;
        $houseNominal = (1.0 + $draws->houseGrowthReal($yearIndex)) * (1.0 + $infl) - 1.0;
        $salaryNominal = (1.0 + $draws->salaryGrowthReal($yearIndex)) * (1.0 + $infl) - 1.0;

        // GIA/cash distribute their income (taxed in projectYear), so they grow at capital
        // only: total return minus the income yield. These rates MUST mirror the income
        // rates in projectYear, so income paid out + capital growth == total return (no
        // double count). ISA reinvests tax-free, so it keeps the full total return.
        $giaCapital = $investNominal - $draws->investmentIncomeYield();
        $cashCapital = $cashNominal - max(0.0, $cashNominal);

        foreach ($state['cash'] as $pid => $v) {
            $state['cash'][$pid] = (int) round($v * (1.0 + $cashCapital));
            $state['gia'][$pid] = (int) round($state['gia'][$pid] * (1.0 + $giaCapital));
            $state['isa'][$pid] = (int) round($state['isa'][$pid] * (1.0 + $investNominal));
            foreach ($state['pots'][$pid] as &$pot) {
                $pot['value'] = (int) round($pot['value'] * (1.0 + $investNominal));
            }
            unset($pot);
        }

        $state['property'] = (int) round($state['property'] * (1.0 + $houseNominal));

        $rentNominal = (1.0 + $state['rentInflationReal']) * (1.0 + $infl) - 1.0;

        $state['salaryFactor'] *= (1.0 + $salaryNominal);
        $state['dbFactor'] *= (1.0 + $this->dbEscalation($infl));
        $state['spFactor'] *= (1.0 + max($infl, 0.025)); // triple-lock proxy
        $state['spendFactor'] *= (1.0 + $infl);
        $state['rentFactor'] *= (1.0 + $rentNominal);
    }

    private function dbEscalation(float $inflation): float
    {
        // A single blended escalation proxy; per-scheme bases are a later refinement.
        return $inflation;
    }

    private function sum(array $byPerson): int
    {
        return array_sum($byPerson);
    }

    private function totalPots(array $state): int
    {
        $total = 0;
        foreach ($state['pots'] as $pots) {
            foreach ($pots as $pot) {
                $total += $pot['value'];
            }
        }

        return $total;
    }

    /**
     * @param  list<YearResult>  $years
     */
    private function everyYear(array $years, callable $predicate): bool
    {
        foreach ($years as $year) {
            if (! $predicate($year)) {
                return false;
            }
        }

        return true;
    }
}
