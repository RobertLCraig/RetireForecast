<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Benefits\PensionCreditCalculator;
use RetireForecast\FinanceEngine\Care\CareMeansTest;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\PensionReliefMethod;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Iht\EstateValuation;
use RetireForecast\FinanceEngine\Iht\EstateValuer;
use RetireForecast\FinanceEngine\Iht\IhtOutcome;
use RetireForecast\FinanceEngine\Iht\IhtResult;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;
use RetireForecast\FinanceEngine\StatePension\StatePensionCalculator;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
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
 *  - Income-tax thresholds are frozen until {@see ForecastSettings::$freezeEndYear}
 *    (April 2031), then index with inflation again — so fiscal drag bites during the
 *    freeze and eases after it (the tax function is homogeneous in income and its
 *    thresholds, so the indexing is applied without rebuilding the band config in the
 *    hot loop; see {@see indexedTotalPence()}).
 *  - DB revaluation/escalation and the State Pension triple lock are modelled as
 *    smooth annual growth factors.
 */
final class PathProjector
{
    /**
     * The tax year unused pension pots start counting towards the estate for Inheritance Tax
     * (the enacted April-2027 rule, Finance Act 2026). A death before then excludes pensions.
     */
    private const PENSIONS_IN_ESTATE_FROM_YEAR = 2027;

    private readonly IncomeTaxCalculator $incomeTax;

    private readonly NationalInsuranceCalculator $ni;

    private readonly StatePensionCalculator $statePension;

    private readonly PensionCreditCalculator $pensionCredit;

    private readonly InheritanceTaxCalculator $iht;

    private readonly CareMeansTest $careMeans;

    public function __construct(private readonly TaxYearConfig $config)
    {
        $this->incomeTax = new IncomeTaxCalculator($config);
        $this->ni = new NationalInsuranceCalculator($config);
        $this->statePension = new StatePensionCalculator($config);
        $this->pensionCredit = new PensionCreditCalculator($config);
        $this->iht = new InheritanceTaxCalculator($config);
        $this->careMeans = new CareMeansTest($config);
    }

    public function project(Household $household, ForecastSettings $settings, PathDraws $draws): ForecastResult
    {
        $state = $this->initialState($household, $settings);

        $years = [];
        $cumInflation = 1.0; // product of (1+inflation) before the current year
        $freezeRefInflation = 1.0; // price level when the income-tax threshold freeze ends
        $depletionYear = null;
        // Who was alive last year, so a death (alive last year, gone now) can be detected and the
        // deceased's estate valued for IHT before settleEstates moves it. Everyone is alive at base.
        $prevAlive = array_fill_keys(array_map(static fn (Person $p): string => $p->id, $household->persons), true);

        for ($yearIndex = 0; ; $yearIndex++) {
            $calendarYear = $settings->baseYear + $yearIndex;

            $alive = [];
            foreach ($household->persons as $person) {
                $age = $state['baseAge'][$person->id] + $yearIndex;
                $alive[$person->id] = $age <= $draws->deathAge($person->id);
            }
            if (! in_array(true, $alive, true)) {
                // The last survivor has died: value the whole remaining estate (which passes to
                // descendants) as the final death and compute its IHT. cumInflation here is the
                // price level at the end of the final living year, so the nominal estate deflates
                // to real correctly.
                if ($settings->modelIht) {
                    $this->recordFinalDeathIht($state, $household, $settings, $draws, $prevAlive, $cumInflation);
                }
                break; // last survivor has died
            }

            // A person alive last year and gone now, while someone still survives, has just died:
            // value their own estate (per-person liquid + pension + their share of the home) for
            // the first death's IHT BEFORE settleEstates moves it to the heir. Two-person households only.
            if ($settings->modelIht) {
                foreach ($household->persons as $person) {
                    if (($prevAlive[$person->id] ?? true) && ! $alive[$person->id]) {
                        $this->recordFirstDeathIht($state, $household, $draws, $person, $cumInflation);
                    }
                }
            }

            // On a death, the surviving partner inherits the deceased's assets — without this
            // a dead owner's money is stranded (counted as wealth but undrawable), which reads
            // falsely as "running out" from the first death. Settle before projecting the year
            // so the survivor can use the inherited money that year.
            $this->settleEstates($state, $household, $alive);

            // Income-tax thresholds are frozen until freezeEndYear, then index with inflation.
            // $thresholdFactor is how far they have risen by this year: 1.0 during the freeze,
            // then the price level relative to the freeze-end year. (If the base year is past
            // the freeze, $freezeRefInflation stays 1.0 and thresholds index from the base.)
            if ($calendarYear === $settings->freezeEndYear) {
                $freezeRefInflation = $cumInflation;
            }
            $thresholdFactor = $calendarYear <= $settings->freezeEndYear ? 1.0 : $cumInflation / $freezeRefInflation;

            $year = $this->projectYear($household, $settings, $draws, $state, $yearIndex, $calendarYear, $alive, $cumInflation, $thresholdFactor);

            if ($depletionYear === null && ! $year->essentialsMet) {
                $depletionYear = $calendarYear;
            }

            // Advance growth factors and balances to the start of next year. growState returns the
            // year's nominal capital growth (untaxed appreciation left in the pots) and the ongoing
            // charges taken out of them. Both are year N -> N+1 flows, so deflate by NEXT year's
            // price level to express the real (purchasing-power) figures — matching the real wealth
            // line's progression — then attach them, so the ladder shows where the pot grows beyond
            // the income it pays out, and what holding it cost.
            $grown = $this->growState($state, $draws, $yearIndex);
            $cumInflation *= (1.0 + $draws->inflation($yearIndex));
            $years[] = $year->withInvestmentGrowth(
                Money::fromPence((int) round($grown['growth'] / $cumInflation)),
                Money::fromPence((int) round($grown['charges'] / $cumInflation)),
                // The pre-deflation twin takes the same two flows undivided, so the nominal
                // view carries the growth and charges the pots actually saw.
                $year->nominal?->withInvestmentGrowth(
                    Money::fromPence($grown['growth']),
                    Money::fromPence($grown['charges']),
                ),
            );

            $prevAlive = $alive; // carry this year's living into next year's death detection

            if ($yearIndex > 200) {
                break; // safety backstop; should never trigger (mortality caps at 110)
            }
        }

        $terminal = end($years) ?: null;

        // The modelled calendar year of each person's death (birthYear + death age), so the
        // milestones layer can show *when* each person dies without re-deriving the death age.
        $deathCalendarYears = [];
        foreach ($household->persons as $person) {
            $deathCalendarYears[$person->id] = (int) $person->dob->format('Y') + $draws->deathAge($person->id);
        }

        return new ForecastResult(
            years: $years,
            essentialsAlwaysMet: $this->everyYear($years, fn (YearResult $y) => $y->essentialsMet),
            fullSpendAlwaysMet: $this->everyYear($years, fn (YearResult $y) => $y->fullSpendMet()),
            depletionCalendarYear: $depletionYear,
            terminalTotalWealth: $terminal ? $terminal->totalWealth : Money::zero(),
            terminalUsableWealth: $terminal ? $terminal->liquidWealth->plus($terminal->pensionWealth) : Money::zero(),
            finalCalendarYear: $terminal ? $terminal->calendarYear : $settings->baseYear,
            deathCalendarYears: $deathCalendarYears,
            careCostRealValue: $state['careRealTotal'] > 0 ? Money::fromPence($state['careRealTotal']) : null,
            iht: $this->buildIhtOutcome($state),
        );
    }

    /**
     * Assemble the path's {@see IhtOutcome} from the recorded per-death IHT results (already
     * deflated to real). Null when IHT was not modelled (no final-death result recorded), so
     * the toggle demonstrably drives the result. The final death always occurs, so its result
     * is the anchor; the first death is present only for a two-person household.
     *
     * @param  array<string, mixed>  $state
     */
    private function buildIhtOutcome(array $state): ?IhtOutcome
    {
        $second = $state['ihtSecondDeath'] ?? null;
        if (! $second instanceof IhtResult) {
            return null;
        }

        $first = ($state['ihtFirstDeath'] ?? null) instanceof IhtResult ? $state['ihtFirstDeath'] : null;
        $total = $second->tax->plus($first?->tax ?? Money::zero());

        return new IhtOutcome($first, $second, $total);
    }

    /**
     * Record the first death's IHT: the deceased's OWN estate — their per-person liquid (cash +
     * ISA + GIA) and pension, plus their share of the home. The couple own the home jointly, so a
     * first death carries half the household's equity (a v1 50/50 split; immaterial for a married
     * couple, whose first death is spousally exempt). On the first death the estate passes to the
     * surviving partner, not to descendants, so the residence nil-rate band never applies here.
     *
     * @param  array<string, mixed>  $state
     */
    private function recordFirstDeathIht(array &$state, Household $household, PathDraws $draws, Person $deceased, float $cumInflation): void
    {
        $married = $household->relationshipStatus === RelationshipStatus::MarriedOrCivilPartnership;

        $liquid = Money::fromPence($state['cash'][$deceased->id] + $state['gia'][$deceased->id] + $state['isa'][$deceased->id]);
        $pension = Money::fromPence($this->personPots($state, $deceased->id));
        $estate = EstateValuer::value(
            $liquid,
            $pension,
            Money::fromPence((int) round($state['property'] / 2)),
            Money::fromPence((int) round($state['mortgageOutstanding'] / 2)),
        );

        $deathYear = (int) $deceased->dob->format('Y') + $draws->deathAge($deceased->id);

        $state['ihtFirstDeath'] = $this->computeDeathIht($estate, spousallyExempt: $married, multiplier: 1, homeToDescendants: false, deathYear: $deathYear, cumInflation: $cumInflation);
    }

    /**
     * Record the final death's IHT: the WHOLE remaining estate (the survivor holds all the liquid
     * and pensions by now — settleEstates moved them — plus the whole home) passing to direct
     * descendants. A married couple's final death has both partners' nil-rate bands available (the
     * transferable NRB / RNRB, multiplier 2, since the whole first estate passed spouse-exempt); a
     * cohabiting couple or a single person has one set.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $prevAlive  who was alive in the final living year
     */
    private function recordFinalDeathIht(array &$state, Household $household, ForecastSettings $settings, PathDraws $draws, array $prevAlive, float $cumInflation): void
    {
        $twoPeople = count($household->persons) === 2;
        $married = $twoPeople && $household->relationshipStatus === RelationshipStatus::MarriedOrCivilPartnership;

        $liquid = Money::fromPence($this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']));
        $estate = EstateValuer::value(
            $liquid,
            Money::fromPence($this->totalPots($state)),
            Money::fromPence($state['property']),
            Money::fromPence($state['mortgageOutstanding']),
        );

        // The final death year is the last survivor's (the latest modelled death among those alive
        // in the final living year), used for the April-2027 pensions-in-estate gate.
        $deathYear = $settings->baseYear;
        foreach ($household->persons as $person) {
            if ($prevAlive[$person->id] ?? false) {
                $deathYear = max($deathYear, (int) $person->dob->format('Y') + $draws->deathAge($person->id));
            }
        }

        $state['ihtSecondDeath'] = $this->computeDeathIht($estate, spousallyExempt: false, multiplier: $married ? 2 : 1, homeToDescendants: $settings->homeToDescendants, deathYear: $deathYear, cumInflation: $cumInflation);
    }

    /**
     * Compute one death's IHT in NOMINAL pounds at the death year — so the frozen nil-rate bands
     * bite against the grown nominal estate (real fiscal drag, matching how the projector treats
     * frozen income-tax thresholds) — then deflate the whole result to REAL today's money for the
     * outcome. A spousally-exempt death (a married couple's first death) is £0 with a note. Unused
     * pension pots enter the estate only from April 2027 (the enacted rule).
     */
    private function computeDeathIht(EstateValuation $estate, bool $spousallyExempt, int $multiplier, bool $homeToDescendants, int $deathYear, float $cumInflation): IhtResult
    {
        $includePensions = $deathYear >= self::PENSIONS_IN_ESTATE_FROM_YEAR;

        if ($spousallyExempt) {
            $total = $estate->estateExcludingPensions->plus($includePensions ? $estate->pensionValue : Money::zero());
            $result = new IhtResult(
                totalEstate: $total,
                nilRateBandUsed: Money::zero(),
                residenceNilRateBandUsed: Money::zero(),
                taxableEstate: Money::zero(),
                rate: $this->config->iht->rate,
                tax: Money::zero(),
                pensionsIncluded: $includePensions,
                warnings: [new Warning(
                    WarningCode::IHT_SPOUSE_EXEMPTION,
                    'Everything passes to the surviving spouse or civil partner, so no Inheritance Tax '
                    .'is due on the first death (the spouse exemption); their unused allowances carry over.',
                )],
            );
        } else {
            $homeToDesc = $homeToDescendants ? $estate->homeEquity : Money::zero();
            $result = $this->iht->compute($estate->estateExcludingPensions, $estate->pensionValue, $includePensions, $homeToDesc, $multiplier);
        }

        return $this->deflateIht($result, 1.0 / $cumInflation);
    }

    /**
     * Deflate an {@see IhtResult} computed in nominal pounds at the death year to real today's
     * money, scaling each money leg by the real factor (the rate, the pensions-included flag and
     * the warnings are unit-free and carry through).
     */
    private function deflateIht(IhtResult $result, float $realFactor): IhtResult
    {
        $r = static fn (Money $m): Money => Money::fromPence((int) round($m->pence * $realFactor));

        return new IhtResult(
            totalEstate: $r($result->totalEstate),
            nilRateBandUsed: $r($result->nilRateBandUsed),
            residenceNilRateBandUsed: $r($result->residenceNilRateBandUsed),
            taxableEstate: $r($result->taxableEstate),
            rate: $result->rate,
            tax: $r($result->tax),
            pensionsIncluded: $result->pensionsIncluded,
            warnings: $result->warnings,
        );
    }

    /**
     * The total value of one person's DC pension pots (nominal pence).
     *
     * @param  array<string, mixed>  $state
     */
    private function personPots(array $state, string $id): int
    {
        $total = 0;
        foreach ($state['pots'][$id] ?? [] as $pot) {
            $total += $pot['value'];
        }

        return $total;
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
        $spClaimYear = [];
        $cash = [];
        $gia = [];
        $giaBasis = [];
        $isa = [];
        $pots = [];
        $lsaUsed = [];
        $giaOverrideBal = [];       // per-person GIA balance carrying a yield override
        $giaOverrideYieldSum = [];  // per-person Σ(balance × override yield)
        $salaryFactor = [];         // per-person running nominal salary factor (1.0 in the base year)
        $salaryGrowthReal = [];     // per-person real salary-growth override (null = use the assumption set)

        foreach ($household->persons as $person) {
            $birthYear = (int) $person->dob->format('Y');
            $baseAge[$person->id] = $settings->baseYear - $birthYear;
            $spaYear[$person->id] = (int) StatePensionAge::for($person->dob)->dateReached->format('Y');
            // Deferring the State Pension delays the CLAIM (not State Pension age itself): the
            // person forgoes payments for the deferral period, then draws the uplifted rate from
            // the later start. Modelled by shifting the claim year by whole years of deferral; the
            // uplift itself is applied by the calculator from the deferral weeks. Age (spaYear)
            // still governs the NI cut-off and the Pension Credit qualifying-age gate.
            $spClaimYear[$person->id] = $spaYear[$person->id] + $this->deferralYears($household, $person->id);
            $cash[$person->id] = 0;
            $gia[$person->id] = 0;
            $giaBasis[$person->id] = 0;
            $isa[$person->id] = 0;
            $pots[$person->id] = [];
            $lsaUsed[$person->id] = 0;
            $salaryFactor[$person->id] = 1.0;
            $salaryGrowthReal[$person->id] = $person->salaryGrowth?->asFraction();
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
                // A per-account income-yield override: accumulate the balance-weighted override
                // rate and the balance it covers, so the person's effective GIA yield can blend
                // overridden accounts with the assumption-set default (see effectiveGiaYield).
                if ($account->yield !== null) {
                    $giaOverrideBal[$pid] = ($giaOverrideBal[$pid] ?? 0) + $account->balance->pence;
                    $giaOverrideYieldSum[$pid] = ($giaOverrideYieldSum[$pid] ?? 0.0)
                        + $account->balance->pence * $account->yield->asFraction();
                }
            }
        }

        // Per-person GIA yield override, held at base-year balance proportions: the share of a
        // person's GIA under an override and its balance-weighted rate. effectiveGiaYield() blends
        // this with the assumption-set yield. (v1: the override share is fixed at base-year weights.)
        $giaOverrideYield = [];
        $giaOverrideShare = [];
        foreach ($household->persons as $person) {
            $overrideBal = $giaOverrideBal[$person->id] ?? 0;
            $totalGia = $gia[$person->id] ?? 0;
            $giaOverrideYield[$person->id] = $overrideBal > 0 ? $giaOverrideYieldSum[$person->id] / $overrideBal : 0.0;
            $giaOverrideShare[$person->id] = $totalGia > 0 ? $overrideBal / $totalGia : 0.0;
        }

        $annuities = [];
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DcPension) {
                $pots[$pension->ownerId][] = [
                    'value' => $pension->currentValue->pence,
                    'plan' => $pension->withdrawalPlan,
                    'firstAccessDone' => false,
                    // Kept apart, not summed: only the member's own contribution is the household's
                    // money (so only it may be funded from surplus) and only it attracts relief.
                    'contribution' => $pension->ongoingContribution->pence,
                    'employerContribution' => $pension->employerContribution->pence,
                    'reliefMethod' => $pension->reliefMethod,
                    'earliestAccessAge' => $pension->earliestAccessAge,
                    'growthOverrideReal' => $pension->growthAssumptionOverride?->asFraction(),
                ];
                $lsaUsed[$pension->ownerId] += $pension->pclsTakenToDate?->pence ?? 0;

                // A planned annuity purchase becomes a pending annuity, bought at its age
                // from this owner's pots (see processAnnuityPurchases).
                if ($pension->annuityPurchase !== null) {
                    $a = $pension->annuityPurchase;
                    $annuities[] = [
                        'ownerId' => $pension->ownerId,
                        'atAge' => $a->atAge,
                        'amount' => $a->amount->pence,
                        'rate' => $a->rate->asFraction(),
                        'escalation' => $a->escalation,
                        'survivorFraction' => $a->survivorFraction?->asFraction(),
                        'purchased' => false,
                        'active' => false,
                        'baseIncomeNominal' => 0,
                        'purchaseCumInflation' => 1.0,
                    ];
                }
            }
        }

        $propertyShare = $household->primaryResidence?->ownershipShare?->asFraction() ?? 1.0;

        // An ordinary capital-and-interest mortgage amortises to zero over its term. Build the
        // month-by-month schedule once here (it depends only on the loan and its terms, never on
        // the path), so every year can read its opening balance and its fixed-nominal instalment.
        // Null = the pre-existing shapes: a static balance, or a lifetime-mortgage roll-up.
        $repaymentTerms = $household->primaryResidence?->repaymentTerms;
        $repaymentSchedule = $repaymentTerms === null ? null : AmortisationSchedule::for(
            $household->primaryResidence?->outstandingMortgage ?? Money::zero(),
            $repaymentTerms,
        );

        return [
            'baseYear' => $settings->baseYear,
            'baseAge' => $baseAge,
            'spaYear' => $spaYear,
            'spClaimYear' => $spClaimYear,
            'cash' => $cash,
            'gia' => $gia,
            'giaBasis' => $giaBasis,
            'isa' => $isa,
            'pots' => $pots,
            'lsaUsed' => $lsaUsed,
            // Flexible access (a UFPLS or drawdown income, planned or ad-hoc) permanently caps
            // that member's money-purchase contributions at the MPAA; mpContributed counts what
            // has gone in THIS year and is reset each year. {@see mpaaHeadroom}.
            'mpaaTriggered' => array_fill_keys(array_keys($lsaUsed), false),
            'mpContributed' => array_fill_keys(array_keys($lsaUsed), 0),
            // The household owns a beneficial share of the home (tenants in common); null = 100%.
            // Value and mortgage are entered whole and scaled to the household's share here, so every
            // downstream use (wealth, the means test, IHT, growth) reflects only the share it owns.
            'property' => (int) round(($household->primaryResidence?->currentValue->pence ?? 0) * $propertyShare),
            // An amortising loan reads its balance from the schedule (the base year's opening
            // balance), so a mortgage already part-way through its term starts where it really is.
            'mortgageOutstanding' => (int) round(
                ($repaymentSchedule?->openingBalanceIn($settings->baseYear)->pence
                    ?? $household->primaryResidence?->outstandingMortgage?->pence
                    ?? 0) * $propertyShare
            ),
            'ownershipShare' => $propertyShare,
            // The whole-property (un-scaled) value, grown in lockstep with the share value. A
            // forced sale needs the whole figure to compute CGT on the household's share of the
            // gain (purchase price is whole too); null-share leaves it equal to `property`.
            'propertyWhole' => $household->primaryResidence?->currentValue->pence ?? 0,
            'mortgageRepaid' => false,
            // A forced sale (MortgageMaturityAction::ForcedSale) sells the home in the redemption
            // year, mid-projection, then the household rents. Flips true at that event.
            'homeSold' => false,
            'annuities' => $annuities, // planned/active lifetime annuities bought from DC pots
            'careRealTotal' => 0, // accumulated real (today's money) care cost incurred on this path
            'estateSettled' => [], // person ids whose assets have passed to the survivor (once each)
            // Death-in-service lump sums recorded in a member's final working year and paid to the
            // survivor the following year (personId => the payout's facts). Drained when paid.
            'deathBenefit' => [],
            // Running nominal growth factors (1.0 in the base year). salaryFactor is per-person so
            // each person's pay can escalate at their own rate (Person::salaryGrowth override).
            'salaryFactor' => $salaryFactor,
            'salaryGrowthReal' => $salaryGrowthReal, // per-person real override (null = assumption set)
            'dbFactor' => 1.0,
            'spFactor' => 1.0,
            'spendFactor' => 1.0,
            'rentFactor' => 1.0,
            'rentInflationReal' => $settings->rentInflationReal?->asFraction() ?? 0.0,
            'propertyGrowthReal' => $household->primaryResidence?->growthAssumptionOverride?->asFraction(),
            // A lifetime-mortgage roll-up rate (fixed nominal, fixed for life); null = the balance
            // is static (a repayment/interest-serviced mortgage), the existing behaviour.
            'mortgageRollUpRate' => $household->primaryResidence?->mortgageRollUpRate?->asFraction(),
            // A voluntary fixed-nominal annual overpayment that reduces a rolled-up lifetime-mortgage
            // balance (null/0 = pure roll-up). The cash to fund it rides on the Mortgage expense line.
            'mortgageOverpaymentAnnual' => $household->primaryResidence?->mortgageOverpaymentAnnual?->pence ?? 0,
            // The amortisation schedule of a capital-and-interest mortgage; null = a static or
            // rolled-up balance. When set it owns BOTH the balance and the payment.
            'repaymentSchedule' => $repaymentSchedule,
            'giaOverrideYield' => $giaOverrideYield,   // per-person balance-weighted override rate
            'giaOverrideShare' => $giaOverrideShare,   // per-person share of GIA under an override
        ];
    }

    /**
     * A person's effective GIA income yield: the base-year balance-weighted blend of any
     * per-account yield overrides with the assumption-set default. With no override the
     * share is 0, so this returns the global yield unchanged.
     *
     * @param  array<string, mixed>  $state
     */
    private function effectiveGiaYield(array $state, string $pid, float $globalYield): float
    {
        $share = $state['giaOverrideShare'][$pid] ?? 0.0;

        return $share * ($state['giaOverrideYield'][$pid] ?? 0.0) + (1.0 - $share) * $globalYield;
    }

    /**
     * On a death, the surviving partner inherits the deceased's assets. Spouses inherit
     * IHT-free, and a DC pot passes to the beneficiary — so without this the dead owner's
     * savings, investments and pension are stranded: still summed into wealth but never
     * drawable (the drawdown closures skip a dead owner), which falsely reads as "essentials
     * not met / ran out of money" from the first death, even with a full pot sitting there.
     *
     * Each estate is settled once (tracked in `estateSettled`), transferring the deceased's
     * cash / ISA / GIA — with a CGT base-cost uplift to the value at death, so the heir is
     * taxed only on later gains — and the remaining pension pot value to the FIRST living
     * person. The deceased's scheduled withdrawals and contributions do NOT carry (they were
     * the deceased's decisions), so no schedule re-runs on the heir; and only the deceased's
     * own assets move, so the heir's own pot is untouched — no double-count. On the last death
     * there is no recipient, so nothing transfers (it stays as the terminal estate).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function settleEstates(array &$state, Household $household, array $alive): void
    {
        $heir = null;
        foreach ($household->persons as $person) {
            if ($alive[$person->id]) {
                $heir = $person->id; // the first living person inherits (respects death order)
                break;
            }
        }
        if ($heir === null) {
            return; // no survivor to inherit
        }

        foreach ($household->persons as $person) {
            $id = $person->id;
            if ($alive[$id] || in_array($id, $state['estateSettled'], true)) {
                continue; // still alive, or already settled
            }
            $state['estateSettled'][] = $id;

            // Liquid assets pass to the heir. The GIA base cost uplifts to its value at death
            // (no CGT on death), so the heir's later disposal is taxed only on gains since.
            $state['cash'][$heir] += $state['cash'][$id];
            $state['isa'][$heir] += $state['isa'][$id];
            $state['gia'][$heir] += $state['gia'][$id];
            $state['giaBasis'][$heir] += $state['gia'][$id];
            $state['cash'][$id] = 0;
            $state['isa'][$id] = 0;
            $state['gia'][$id] = 0;
            $state['giaBasis'][$id] = 0;

            // The remaining pension value passes as inherited drawdown: drawable for shortfalls,
            // but with no scheduled withdrawals or contributions of its own (those were the
            // deceased's), so it neither re-runs a schedule on the heir nor double-dips their pot.
            $inherited = 0;
            foreach ($state['pots'][$id] as $pot) {
                $inherited += $pot['value'];
            }
            if ($inherited > 0) {
                // An inherited DC pot is a beneficiary drawdown — accessible at any age (access age 0);
                // it grows at the blended assumption rate (the deceased's per-pot override is not carried).
                $state['pots'][$heir][] = ['value' => $inherited, 'plan' => [], 'firstAccessDone' => true, 'contribution' => 0, 'employerContribution' => 0, 'reliefMethod' => null, 'earliestAccessAge' => 0, 'growthOverrideReal' => null];
            }
            $state['pots'][$id] = [];
        }
    }

    /**
     * Record an employer death-in-service lump sum, if this year is $person's LAST living year and
     * they are still in employment then.
     *
     * Cover is conditional on being *in service*, which is exactly why it is worth modelling: it
     * **ceases when employment does**, so a household relying on it loses it at retirement — in the
     * years a survivor has the longest left to fund. A member who dies after their planned
     * retirement age works no fraction of that year, so nothing is recorded and nothing is paid.
     *
     * The sum is sized on the member's FULL-year salary in the year of death (a multiple of
     * contractual annual salary, not of the part-year a retirement year actually pays), and stashed
     * with the two facts its tax treatment needs later: their age at death, and how much of their
     * lump-sum allowance they had used.
     *
     * @param  array<string, mixed>  $state
     */
    private function recordDeathInServiceBenefit(array &$state, Person $person, int $age, PathDraws $draws): void
    {
        if ($person->deathInServiceCover === null
            || $person->employmentStatus !== EmploymentStatus::Employed
            || $person->grossSalary === null
            || $age !== $draws->deathAge($person->id)
            || self::workFraction($person, $age) <= 0.0) {
            return;
        }

        $annualSalary = Money::fromPence((int) round($person->grossSalary->pence * $state['salaryFactor'][$person->id]));

        $state['deathBenefit'][$person->id] = [
            'gross' => $person->deathInServiceCover->amountAt($annualSalary)->pence,
            'ageAtDeath' => $age,
            'lsaUsed' => $state['lsaUsed'][$person->id],
        ];
    }

    /**
     * Pay any recorded death-in-service lump sum whose member has now died, to the first living
     * person (the same heir convention {@see settleEstates} uses), and split it into its tax-free
     * and taxable parts. Returns null when there is nothing to pay.
     *
     * **Tax treatment** (verified 2026-07-31 against HMRC's Pensions Tax Manual PTM073010 and
     * gov.uk's lump sum allowance guidance):
     *  - Death **under 75**: tax-free up to the deceased's REMAINING lump sum and death benefit
     *    allowance (the £1,073,100 LSDBA less the lump-sum allowance they had already used through
     *    tax-free cash); anything above it is taxed as the recipient's income at their marginal rate.
     *  - Death at **75 or over**: the whole lump sum is taxable as the recipient's pension income.
     *    (The 45% special lump sum death benefits charge applies only where the recipient is a
     *    non-qualifying person, e.g. a trust — not a surviving partner, so it is not modelled.)
     *
     * **Inheritance Tax:** none. Death-in-service benefits from a registered pension scheme are
     * outside the IHT net, and are explicitly excluded from the April-2027 measure that brings
     * unused pension funds into the estate — so the payout is deliberately NOT added to the
     * deceased's estate here. It becomes the survivor's own money, and is in their estate at the
     * second death like any other cash, which the projector already handles.
     *
     * If nobody survives the member (a one-person household), the projection has ended: the money
     * passes outside the estate to their beneficiaries and cannot affect the forecast, so the
     * record is simply never drained.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @return array{heirId: string, taxFree: int, taxable: int}|null
     */
    private function collectDeathInServiceBenefit(array &$state, Household $household, array $alive): ?array
    {
        if ($state['deathBenefit'] === []) {
            return null;
        }

        $heir = null;
        foreach ($household->persons as $person) {
            if ($alive[$person->id]) {
                $heir = $person->id;
                break;
            }
        }
        if ($heir === null) {
            return null;
        }

        $taxFree = 0;
        $taxable = 0;
        foreach ($state['deathBenefit'] as $pid => $benefit) {
            if ($alive[$pid] ?? false) {
                continue; // recorded, but they have not died yet — this is still their final living year
            }
            unset($state['deathBenefit'][$pid]);

            if ($benefit['ageAtDeath'] >= 75) {
                $taxable += $benefit['gross'];

                continue;
            }

            $allowanceLeft = max(0, $this->config->pension->lumpSumAndDeathBenefitAllowance->pence - $benefit['lsaUsed']);
            $free = min($benefit['gross'], $allowanceLeft);
            $taxFree += $free;
            $taxable += $benefit['gross'] - $free;
        }

        if ($taxFree === 0 && $taxable === 0) {
            return null;
        }

        return ['heirId' => $heir, 'taxFree' => $taxFree, 'taxable' => $taxable];
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
        float $thresholdFactor = 1.0,
    ): YearResult {
        $ages = [];
        $taxablePerPerson = [];   // nominal non-savings taxable income
        $taxFreeCashNominal = 0;  // pension tax-free cash received this year
        $taxFreeIncomeNominal = 0; // tax-free income streams (e.g. DLA) received this year
        $grossIncomeNominal = 0;
        // Nominal income split by canonical source (YearResult::INCOME_SOURCES).
        $src = array_fill_keys(YearResult::INCOME_SOURCES, 0);

        // The Money Purchase Annual Allowance is a per-TAX-YEAR cap, so the running total of
        // what has been paid into money-purchase pots resets here, before any of this year's
        // contributions (employer, net-pay, or from surplus) are made.
        $state['mpContributed'] = array_fill_keys(array_keys($state['mpContributed']), 0);

        // Any annuity purchases due this year convert part of a DC pot into a lifetime income
        // before the year's income is assembled, so the pot is reduced and the annuity pays
        // from its purchase year.
        $this->processAnnuityPurchases($state, $yearIndex, $alive, $cumInflation);

        foreach ($household->persons as $person) {
            $age = $state['baseAge'][$person->id] + $yearIndex;
            $ages[$person->id] = $age;
            $taxablePerPerson[$person->id] = 0;

            if (! $alive[$person->id]) {
                continue;
            }

            // Employment earnings, prorated in the retirement year (they stop part-way through it,
            // so the final year is a fraction of full salary — not a whole year, and not zero).
            $earnings = 0;
            if ($person->employmentStatus === EmploymentStatus::Employed && $person->grossSalary !== null) {
                $fraction = self::workFraction($person, $age);
                $earnings = (int) round($person->grossSalary->pence * $state['salaryFactor'][$person->id] * $fraction);

                // The employer's contribution is the EMPLOYER's money: it goes straight into the
                // pot while the member is working, prorated in a part-year, and is never funded
                // from the household's surplus (which would charge them for someone else's money
                // and silently drop it in a year they had none).
                $this->payEmployerContributions($state, $person->id, $state['spendFactor'], $fraction);

                // A net-pay contribution is deducted from GROSS pay before the employer runs PAYE,
                // so it never reaches the household's cash and relief is given at the member's full
                // marginal rate immediately (no NI saving — that is salary sacrifice). Subtracting
                // it from earnings before BOTH the tax pass and the spendable-income total is what
                // gives the relief: there is no second, parallel relief calculation to drift from
                // the engine's one income-tax pass. Capped at pay — you cannot give up more than
                // you earn — which also stops it by itself at retirement, when pay ends.
                $earnings -= $this->payNetPayContributions($state, $person->id, $state['spendFactor'], $fraction, $earnings);
            }

            // Guaranteed pension / other income, kept split by source.
            $db = $this->dbIncome($household, $person->id, $age, $state['dbFactor']);
            $sp = $this->statePensionIncome($household, $person->id, $calendarYear, $state['spClaimYear'][$person->id], $state['spFactor']);
            $otherTaxable = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: true);
            $taxFreeStream = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: false);

            // Planned DC withdrawals due at this age.
            $wd = $this->plannedWithdrawals($state, $person->id, $age);

            // Employer death-in-service cover: recorded in the member's LAST living year, while
            // the salary that sizes it and the lump-sum allowance they have used are both still
            // known. It is PAID next year, when the death is settled — see collectDeathInServiceBenefit().
            $this->recordDeathInServiceBenefit($state, $person, $age, $draws);

            // DB commutation: a tax-free lump sum taken at the member's retirement (the pension
            // itself was reduced for it in dbIncome). Routed as pension tax-free cash, like a PCLS.
            $commutationCash = $this->commutationLumpSumNominal($household, $person->id, $age, $state['dbFactor']);

            $taxablePerPerson[$person->id] += $earnings + $db + $sp + $otherTaxable + $wd['taxable'];
            $taxFreeIncomeNominal += $taxFreeStream;
            $taxFreeCashNominal += $wd['taxFree'] + $commutationCash;

            $src['salary'] += $earnings;
            $src['defined_benefit'] += $db;
            $src['state_pension'] += $sp;
            $src['other_taxable'] += $otherTaxable;
            $src['tax_free_income'] += $taxFreeStream;
            $src['pension_lump_sum'] += $wd['taxFree'] + $commutationCash;
            $src['pension_drawdown'] += $wd['taxable'];
        }

        // Annuity income from any purchased annuities: a guaranteed lifetime income, taxable
        // like other income, paid to the surviving partner at the joint fraction after the
        // annuitant dies. Assigned before the tax pass so it is taxed and counts as assessable
        // income for the Pension Credit test.
        foreach ($this->annuityIncomeNominal($state, $household, $alive, $cumInflation) as $pid => $annuityAmount) {
            $taxablePerPerson[$pid] += $annuityAmount;
            $src['other_taxable'] += $annuityAmount;
        }

        // Employer death-in-service cover: a member who died last year still in employment leaves
        // the scheme's lump sum to their survivor, paid now. The tax-free part joins the year's
        // tax-free cash; the taxable part (death at 75+, or above the deceased's remaining lump sum
        // and death benefit allowance) joins the survivor's taxable income so it runs through the
        // engine's ONE income-tax pass rather than a parallel calculation. It is a capital receipt
        // for the means test, not income, so it is excluded from the Pension Credit assessment
        // ($meansTestExcluded) — the banked cash raises tariff income from the following year instead.
        $meansTestExcluded = [];
        $deathBenefit = $this->collectDeathInServiceBenefit($state, $household, $alive);
        if ($deathBenefit !== null) {
            $taxablePerPerson[$deathBenefit['heirId']] += $deathBenefit['taxable'];
            $meansTestExcluded[$deathBenefit['heirId']] = $deathBenefit['taxable'];
            $taxFreeCashNominal += $deathBenefit['taxFree'];
            $src['death_in_service'] += $deathBenefit['taxable'] + $deathBenefit['taxFree'];
        }

        // Survivor DB pension: when a DB member dies, a scheme with a survivor's fraction continues
        // that fraction of the pension to the surviving partner for life (the joint-life analogue of
        // the annuity above). Without this the guaranteed DB income silently dropped to £0 on the
        // member's death. Taxed and Pension-Credit-assessable like the member's own DB income.
        foreach ($this->survivorDbIncomeNominal($household, $alive, $state['dbFactor']) as $pid => $dbSurvivor) {
            $taxablePerPerson[$pid] += $dbSurvivor;
            $src['defined_benefit'] += $dbSurvivor;
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
            $giaDividends = (int) round($state['gia'][$person->id] * $this->effectiveGiaYield($state, $person->id, $giaYield));
            $investmentIncome = $cashInterest + $giaDividends;

            $taxable = $taxablePerPerson[$person->id];
            $grossIncomeNominal += $taxable + $investmentIncome;
            // Combined pass: non-savings, then cash interest (savings, with the PSA), then
            // GIA dividends (dividend allowance + rates) stacked on top. The hot loop only
            // needs the total, so use the lean integer twin of compute() (same band core).
            $tax = $this->indexedTotalPence(new TaxableIncome(
                Money::fromPence($taxable),
                Money::fromPence($cashInterest),
                Money::fromPence($giaDividends),
            ), $thresholdFactor);
            $ni = $this->niForPerson($household, $person->id, $state, $yearIndex);
            $totalTaxNominal += $tax + $ni;
            $netCashNominal += $taxable + $investmentIncome - $tax - $ni;
            $src['investment_income'] += $investmentIncome;
        }
        $grossIncomeNominal += $taxFreeCashNominal + $taxFreeIncomeNominal;

        // Documented one-off capital receipts ({@see Household::$capitalReceipts} — a family
        // gift / inheritance / outside-asset sale) land in their calendar year, inflated to
        // that year's prices, as tax-free spendable cash: a gift is not income, so it joins
        // neither the tax pass nor the means test's assessable income (the banked cash raises
        // tariff income from the following year instead, as in reality). If the owner has died
        // the household still receives it; any unspent residue banks to the first living
        // person's cash via the ordinary surplus path below.
        foreach ($household->capitalReceipts as $receipt) {
            if ($receipt->calendarYear !== $calendarYear) {
                continue;
            }
            $amount = (int) round($receipt->amount->pence * $cumInflation);
            $netCashNominal += $amount;
            $grossIncomeNominal += $amount;
            $src['capital_receipt'] += $amount;
        }

        // Buy-to-let finance-cost restriction (since April 2020): a landlord can no longer deduct
        // mortgage interest from rental profit, but gets a basic-rate (20%) tax reducer on the
        // lower of the finance cost and the rental profit. Modelled when the home is LET: the
        // mortgage interest is charged as spend above (a real outflow, no full deduction), and
        // here the household tax falls by 20% × min(interest, rental income). Without this the
        // rent was taxed at the full marginal rate with no relief for the interest — overstating
        // the tax on a let property. v1: household-level (joint-ownership split not separated),
        // rental profit approximated by rental income (no other let-expenses modelled), capped at
        // the tax due (a reducer cannot create a refund).
        // The relievable finance cost is mortgage INTEREST only — capital repaid never attracts
        // relief. An amortising loan knows its own interest for the year (falling as the balance
        // falls); otherwise the whole Mortgage expense line is interest (an interest-only loan),
        // inflated like the spend it rides on.
        $financeCost = $state['repaymentSchedule'] !== null
            ? (int) round($state['repaymentSchedule']->interestIn($calendarYear)->pence * $state['ownershipShare'])
            : (int) round($household->expenseProfile->mortgageCosts()->pence * $state['spendFactor']);
        if (($household->primaryResidence?->isLet ?? false) && $financeCost > 0) {
            $rentalIncome = $this->rentalIncomeNominal($household, $alive, $ages, $cumInflation);
            $reducerBase = min($financeCost, $rentalIncome);
            $credit = min(
                (int) round($reducerBase * $this->config->incomeTax->basicRate->asFraction()),
                $totalTaxNominal,
            );
            $totalTaxNominal -= $credit;
            $netCashNominal += $credit;
        }

        // Household spend (nominal), with the survivor factor when only one remains.
        $aliveCount = count(array_filter($alive));

        // Means-tested benefit (Pension Credit Guarantee Credit): a tax-free top-up to the
        // household's appropriate minimum guarantee, credited as income before any shortfall
        // is funded — so a sale that turns the exempt home into assessable capital (raising the
        // tariff income) erodes it in-projection, the downsizing trap made visible.
        $benefitNominal = $this->meansTestedBenefitNominal($household, $state, $alive, $calendarYear, $taxablePerPerson, $aliveCount, $meansTestExcluded);
        $netCashNominal += $benefitNominal;
        $grossIncomeNominal += $benefitNominal;
        $src['means_tested_benefit'] += $benefitNominal;

        $survivor = $aliveCount === 1 ? $household->expenseProfile->survivorSpendFactor->asFraction() : 1.0;

        // Spend is read at the reference person's age this year, so an age-banded plan (the
        // "smile") steps with age. The reference is the first-declared person — the same
        // convention as the one-off costs below, and the same flagged v1 limit (a couple with
        // very different ages tracks person 1). A flat plan has one band, so every age returns
        // the one figure — byte-identical to the pre-smile engine.
        $referenceAge = $ages[array_key_first($ages)] ?? 0;

        // Employment-linked costs (e.g. commuting) are charged only while someone is still
        // earning; once everyone has retired they stop. They are essential, so drop them from
        // both the target and the essential floor in years no one works.
        $targetPence = $household->expenseProfile->targetAnnualSpendAt($referenceAge)->pence;
        $essentialPence = $household->expenseProfile->essentialAnnualSpendAt($referenceAge)->pence;
        if (! $this->anyoneWorking($household, $alive, $state, $yearIndex)) {
            $employment = $household->expenseProfile->employmentCosts()->pence;
            $targetPence = max(0, $targetPence - $employment);
            $essentialPence = max(0, $essentialPence - $employment);
        }

        // Mortgage redemption: when the current home's mortgage term ends and the chosen action
        // is to repay it from capital, the outstanding balance is a one-off outflow that year
        // (funded from assets, like any one-off). A fixed-£ debt, so it is already nominal. If the
        // assets are not there the shortfall surfaces, flagging the keep-the-home option as
        // unaffordable. Refinance rolls the loan over (no event); a forced sale is handled by the
        // block just below. Once redeemed, the ongoing mortgage *payment* stops too (dropped just
        // below), so a repay-and-stay path is not charged both the repayment and the payment.
        $repayOneOff = 0;
        $home = $household->primaryResidence;
        if ($home?->mortgageRedemptionYear !== null
            && $home->mortgageMaturityAction === MortgageMaturityAction::RepayFromCapital
            && ! $state['mortgageRepaid']
            && $state['mortgageOutstanding'] > 0
            && $calendarYear >= $home->mortgageRedemptionYear) {
            $repayOneOff = $state['mortgageOutstanding'];
            $state['mortgageOutstanding'] = 0;
            $state['mortgageRepaid'] = true;
        }

        // Forced sale: the mortgage is called for redemption and cannot be refinanced, so the home
        // must be sold that year. Unlike the year-0 sell variants (which can only sell at the
        // start), this sells mid-projection at the grown value: net proceeds are freed into liquid
        // wealth, the debt is cleared, and from this year on the household rents and pays no
        // property costs — the realistic path, not the impossible "keep the home for ever". The
        // sale is decomposed by the shared HousingProceeds so it reconciles (parts sum to net); CGT
        // is £0 for a home lived in throughout, partial-PRR for an ever-let one.
        if ($home?->mortgageRedemptionYear !== null
            && $home->mortgageMaturityAction === MortgageMaturityAction::ForcedSale
            && ! $state['homeSold']
            && $calendarYear >= $home->mortgageRedemptionYear) {
            $proceeds = HousingProceeds::compute(
                Money::fromPence($state['propertyWhole']),
                $home->outstandingMortgage ?? Money::zero(),
                $settings->sellingCosts,
                $home->cgtHistory,
                $home->ownershipShare,
                $this->config,
            );

            // The net proceeds become investable liquid wealth in the first living person's GIA
            // (drawable now, invested per the run's assumptions and drawn per the strategy). Cost
            // basis = proceeds, so no latent gain is taxed on a later disposal. Once in the GIA the
            // freed equity is assessable capital for Pension Credit (it is no longer the exempt
            // main residence), so a forced sale can erode the award / cross the £16k cliff.
            $owner = $this->firstLiving($household, $alive);
            if ($owner !== null) {
                $state['gia'][$owner] += $proceeds->netProceeds->pence;
                $state['giaBasis'][$owner] += $proceeds->netProceeds->pence;
            }

            // Clear the home and its debt; flip onto a renting footing from here.
            $state['property'] = 0;
            $state['propertyWhole'] = 0;
            $state['mortgageOutstanding'] = 0;
            $state['mortgageRepaid'] = true; // stops the ongoing mortgage payment (dropped just below)
            $state['homeSold'] = true;
        }

        // Once the mortgage is redeemed its ongoing payment stops (unlike service charge / ground
        // rent, which continue while the home is owned) — drop the while_mortgaged spend from the
        // redemption year on. Sell variants already removed it via withoutPropertyCosts.
        //
        // The same "Mortgage" expense line is dropped ALWAYS when the home carries a
        // capital-and-interest mortgage: there the engine charges the amortisation schedule's own
        // fixed-nominal instalment instead (added below, after the CPI and survivor multiplies),
        // so the schedule is the single definition of the payment and the two cannot double-count.
        if ($state['mortgageRepaid'] || $state['repaymentSchedule'] !== null) {
            $mortgagePay = $household->expenseProfile->mortgageCosts()->pence;
            $targetPence = max(0, $targetPence - $mortgagePay);
            $essentialPence = max(0, $essentialPence - $mortgagePay);
        }

        // After a forced sale the home is gone, so its property costs (service charge / ground
        // rent — the while_owning_home bucket) stop too, alongside the running costs below. The
        // year-0 sell variants drop these via withoutPropertyCosts; here they drop from the sale year.
        if ($state['homeSold']) {
            $propCosts = $household->expenseProfile->propertyCosts()->pence;
            $targetPence = max(0, $targetPence - $propCosts);
            $essentialPence = max(0, $essentialPence - $propCosts);
        }

        // Home-ownership costs can outpace inflation: the property-costs bucket carries an
        // optional REAL growth rate, compounded per projection year here in real pence — the
        // spendFactor below then adds the CPI everyone rides, so the nominal growth is CPI + the
        // rate. Charged only while the home is still owned: the sell variants strip the bucket
        // (propertyCosts() is zero) and a forced sale subtracts it above, so the escalation
        // follows the bucket for free. Added before the survivor/CPI multiply so it is treated
        // exactly like the base bucket it grows.
        $propertyGrowth = $household->expenseProfile->propertyCostsRealGrowth()->asFraction();
        if ($propertyGrowth > 0.0 && ! $state['homeSold']) {
            $escalation = (int) round($household->expenseProfile->propertyCosts()->pence * ((1.0 + $propertyGrowth) ** $yearIndex - 1.0));
            $targetPence += $escalation;
            $essentialPence += $escalation;
        }

        $spendNominal = (int) round($targetPence * $state['spendFactor'] * $survivor)
            + $this->oneOffCostsNominal($household, $ages, $cumInflation)
            + $repayOneOff;
        $essentialNominal = (int) round($essentialPence * $state['spendFactor'] * $survivor);

        // A capital-and-interest mortgage instalment is added HERE, after the CPI and survivor
        // multiplies, because it is neither: it is FIXED NOMINAL (a £1,318.54 instalment is
        // £1,318.54 in year 16, falling in real terms), and the survivor owes the lender exactly
        // what the couple owed — a death does not shrink it the way it shrinks the food bill. It
        // is an essential cost (the alternative is repossession), it steps when the deal rate
        // reverts, and it stops dead at the end of the term, when the schedule returns zero.
        if ($state['repaymentSchedule'] !== null && ! $state['mortgageRepaid'] && ! $state['homeSold']) {
            $instalmentNominal = (int) round(
                $state['repaymentSchedule']->paymentIn($calendarYear)->pence * $state['ownershipShare']
            );
            $spendNominal += $instalmentNominal;
            $essentialNominal += $instalmentNominal;
        }

        // Rent (the "sell and rent" leg) is an essential cost with its own inflation. It applies
        // once the household no longer owns a home: always for a year-0 rent variant (no
        // primaryResidence), or from the sale year for a forced sale. An owner still in the home
        // pays no rent (even where a post-sale rent figure is set for the forced-sale years).
        $ownsHome = $home !== null && ! $state['homeSold'];
        if ($settings->annualRent !== null && ! $ownsHome) {
            $rentNominal = (int) round($settings->annualRent->pence * $state['rentFactor']);
            $spendNominal += $rentNominal;
            $essentialNominal += $rentNominal;
        }

        // Property running costs (maintenance, insurance, council tax) for owners are
        // essential too — the counterpart to a renter's rent. They stop once the home is sold.
        if ($household->primaryResidence?->runningCosts !== null && ! $state['homeSold']) {
            // Only the household's share of the running costs (it owns a share of the home, entered whole).
            $runningNominal = (int) round($household->primaryResidence->runningCosts->pence * $state['spendFactor'] * $state['ownershipShare']);
            $spendNominal += $runningNominal;
            $essentialNominal += $runningNominal;
        }

        // Late-life care costs (a Monte Carlo risk; the draws return 0 for the deterministic and
        // historical views). Care is an essential outflow, so it lifts both the target and the
        // essential floor and is funded like any spend; the real total is accumulated for the
        // result so the risk is visible, not silently buried in the success rate. careAnnualCost
        // is the gross self-funder fee in today's money, inflated by spendFactor like the rest
        // of spend — the means test then caps each resident's year at what the household
        // actually bears (a self-funder pays the full fee; once their own capital falls to the
        // upper limit the local authority pays the balance above the income-based contribution).
        // Assessed per person, England's individual assessment: the resident's own accounts,
        // their own taxable income, and the home only when no partner still lives in it (or it
        // is let) — see careAssessableCapital.
        // Care fees are the fastest-inflating major late-life cost (largely National-Living-Wage-
        // pinned staff cost, ratcheted above prices), so they carry an optional REAL escalation on
        // top of the CPI everyone rides — compounded per projection year here in real pence, exactly
        // as the property-costs bucket is above. Zero rate = flat-real (the sampled fee times CPI),
        // the pre-2026-07-18 behaviour, so a null-careCostRealGrowth set reproduces byte-identically.
        $careGrowth = $draws->careCostRealGrowth();
        $careEscalation = $careGrowth > 0.0 ? (1.0 + $careGrowth) ** $yearIndex : 1.0;

        // Pension Credit is assessable INCOME for the care financial assessment: the charging
        // regulations take income into account unless it is expressly disregarded, and Guarantee
        // Credit is not disregarded. Being tax-free it never reaches $taxablePerPerson, so a
        // resident on the credit used to be assessed as if the state top-up were not theirs to
        // contribute: the household banked it as income and was never charged it, while in life
        // it is handed to the home. Counting it makes the pair reconcile: the award is credited
        // as income above and charged back here, so a fully funded resident's credit is a wash.
        // The household award is split per living member, because England assesses each resident
        // individually and a couple with one partner in permanent care is treated as two single
        // people for the credit — half of a couple's award is the resident's own money.
        // v1 flag: that couple award is not re-computed as two single awards (two singles get
        // more than a couple), so a couple's resident share is if anything understated.
        $pensionCreditPerPerson = $aliveCount > 0 ? intdiv($benefitNominal, $aliveCount) : 0;

        $careChargedNominal = 0;
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            $feeReal = $draws->careAnnualCost($person->id, $ages[$person->id]);
            if ($feeReal <= 0) {
                continue;
            }
            $feeReal = (int) round($feeReal * $careEscalation);
            $careChargedNominal += $this->careMeans->annualCharge(
                grossAnnualFee: Money::fromPence((int) round($feeReal * $state['spendFactor'])),
                capital: Money::fromPence($this->careAssessableCapital($household, $state, $person->id, $aliveCount)),
                assessableAnnualIncome: Money::fromPence($taxablePerPerson[$person->id] + $pensionCreditPerPerson),
                peaUprating: $state['spendFactor'],
            )->pence;
        }
        if ($careChargedNominal > 0) {
            $spendNominal += $careChargedNominal;
            $essentialNominal += $careChargedNominal;
            $state['careRealTotal'] += (int) round($careChargedNominal / $state['spendFactor']);
        }

        // CGT on GIA gains realised AT the base date ({@see Household::$realisedGainsAtStart} —
        // a year-0 purchase savings draw that sold GIA holdings). Charged up-front here, before
        // the shortfall is funded, so a CGT bill the year's cash cannot cover is itself funded
        // (or surfaces as unmet spend) like any other cost. The seed gains are then passed into
        // fundShortfall so the annual exempt amount is shared ONCE between the seed and any
        // in-year disposal — a year-0 disposal is taxed exactly once, never twice, never free.
        $seedGains = [];
        if ($yearIndex === 0 && $household->realisedGainsAtStart !== []) {
            foreach ($household->realisedGainsAtStart as $pid => $gain) {
                $seedGains[$pid] = $gain->pence;
            }
            $seedCgt = $this->capitalGainsTax($seedGains, $taxablePerPerson, $alive);
            if ($seedCgt > 0) {
                $totalTaxNominal += $seedCgt;
                $netCashNominal -= $seedCgt;
            }
        }

        // Fund any shortfall from assets per the drawdown strategy.
        $shortfall = $spendNominal - $netCashNominal;
        $fundedNominal = 0;
        if ($shortfall > 0) {
            $funded = $this->fundShortfall($household, $settings, $state, $alive, $ages, $taxablePerPerson, $shortfall, $thresholdFactor, $benefitNominal > 0, $seedGains);
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

        // Round each wealth leg once; YearResult derives total wealth from those rounded
        // parts (liquid + pension + home equity net of the mortgage) — never round a raw
        // sum independently, or the total drifts from its legs by a penny
        // (round-of-sum != sum-of-rounds). Data-integrity rule: a reported total has one
        // definition, built from its components.
        //
        // One assembly, two money bases: $m maps this year's nominal pence to the reported
        // Money, so the real year and its pre-deflation twin are built from the SAME integers.
        // The twin is what a nominal-pounds view reads; re-inflating the real figures instead
        // would drift from these by the rounding the deflation threw away.
        $build = fn (callable $m, ?YearResult $nominal): YearResult => new YearResult(
            yearIndex: $yearIndex,
            calendarYear: $calendarYear,
            ages: $ages,
            aliveCount: $aliveCount,
            grossIncome: $m($grossIncomeNominal),
            totalTax: $m($totalTaxNominal),
            netIncome: $m($netCashNominal),
            spendTarget: $m($spendNominal),
            essentialSpend: $m($essentialNominal),
            shortfallFunded: $m($fundedNominal),
            unmetSpend: $m($unmetNominal),
            essentialsMet: $essentialsMet,
            liquidWealth: $m($liquid),
            pensionWealth: $m($pension),
            propertyWealth: $m($state['property']),
            incomeBySource: array_map($m, $src),
            mortgageBalance: $m($state['mortgageOutstanding']),
            nominal: $nominal,
        );

        return $build($r, $build(Money::fromPence(...), null));
    }

    /**
     * Pension Credit Guarantee Credit for the household this year, as annual nominal pence.
     * It tops the household's assessable income up to the appropriate minimum guarantee
     * (single or couple, plus the severe-disability addition when the household qualifies —
     * a single disabled member, or a couple where both are disabled — and the carer addition
     * when a living member cares for a disabled partner), paid only once every living member
     * has reached State Pension age
     * (the qualifying-age / mixed-age-couple gate). Assessable income is the household's
     * taxable income (State Pension, pensions, earnings, drawdown); disability benefits and
     * actual investment income are disregarded — capital is assessed via the tariff instead,
     * on liquid wealth only (the home and, v1, pension pots are excluded). The guarantee is
     * uprated by the same triple-lock proxy as the State Pension; the capital thresholds stay
     * frozen, so over time a fixed disregard captures more capital in real terms (as in life).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $taxablePerPerson
     * @param  array<string, int>  $excludedFromAssessable  taxable receipts that are CAPITAL for the
     *                                                      means test, not income (a death-in-service
     *                                                      lump sum): taxed as income, assessed as capital
     */
    private function meansTestedBenefitNominal(Household $household, array $state, array $alive, int $calendarYear, array $taxablePerPerson, int $aliveCount, array $excludedFromAssessable = []): int
    {
        $weeksPerYear = $this->config->statePension->weeksPerYear;

        // Qualifying-age gate: every living member must be at/over State Pension age.
        $assessableAnnual = 0;
        $living = [];
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            if ($calendarYear < $state['spaYear'][$person->id]) {
                return 0;
            }
            $assessableAnnual += $taxablePerPerson[$person->id] - ($excludedFromAssessable[$person->id] ?? 0);
            // A paused (deferred) State Pension is still assessable income for Pension Credit — count
            // the notional undeferred amount during the deferral window, since the paid figure is 0
            // there (deferring must not conjure Pension Credit it wouldn't otherwise get).
            $assessableAnnual += $this->notionalDeferredStatePensionNominal(
                $household, $person->id, $calendarYear,
                $state['spaYear'][$person->id], $state['spClaimYear'][$person->id], $state['spFactor'],
            );
            $living[$person->id] = $person;
        }

        // Severe-disability addition: a single disabled pensioner qualifies on their own
        // benefit (single rate); a COUPLE qualifies only when BOTH partners receive a
        // qualifying disability benefit (a non-disabled co-resident partner blocks it — the
        // disabled partner is not "living alone"), and then at the couple rate. So one
        // partner on DLA in a couple gives no addition, not the single rate.
        $disabledCount = 0;
        foreach ($living as $person) {
            if ($person->receivesDisabilityBenefit) {
                $disabledCount++;
            }
        }
        $severeDisability = $aliveCount === 1 ? $disabledCount === 1 : $disabledCount >= 2;

        // Carer addition: a living member with (underlying) entitlement to Carer's Allowance —
        // i.e. caring for a living partner who receives a qualifying disability benefit. This is
        // the correct addition where a couple has one disabled partner and the other cares for
        // them; it does not remove the disabled partner's own severe-disability addition.
        $carer = false;
        foreach ($living as $carerId => $person) {
            if (! $person->caresForPartner) {
                continue;
            }
            foreach ($living as $partnerId => $partner) {
                if ($partnerId !== $carerId && $partner->receivesDisabilityBenefit) {
                    $carer = true;
                    break 2;
                }
            }
        }

        $assessableIncomeWeekly = Money::fromPence((int) round($assessableAnnual / $weeksPerYear));

        // Assessable capital = liquid wealth, plus — when the home is LET (the household lives
        // elsewhere) — its equity, because a let property is not the exempt main residence. So
        // letting it out erodes Pension Credit and can cross the £16k cliff, just as selling does.
        $capitalPence = $this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']);
        if ($household->primaryResidence?->isLet) {
            $capitalPence += max(0, $state['property'] - $state['mortgageOutstanding']);
        }
        $capital = Money::fromPence($capitalPence);

        $applicableBase = $this->pensionCredit->applicableAmountWeekly($aliveCount === 2, $severeDisability, $carer);
        $applicableWeekly = Money::fromPence((int) round($applicableBase->pence * $state['spFactor']));

        return $this->pensionCredit->award($applicableWeekly, $assessableIncomeWeekly, $capital)
            ->guaranteeCreditWeekly->pence * $weeksPerYear;
    }

    /**
     * The capital assessed against a care-home resident this year (nominal pence). England
     * assesses the individual: the resident's own accounts (cash / GIA / ISA — the engine's
     * accounts are individually owned; pension pots are disregarded as capital, matching the
     * Pension Credit treatment, while drawdown income is assessed as income instead). The home
     * is disregarded while a partner still lives in it; it counts once the resident lives alone
     * (the 12-week disregard and deferred-payment mechanics are below the annual grid — equity
     * funding the fees is the same outcome) or when it is LET (not the main residence, the same
     * rule the Pension Credit test above applies). A couple's jointly held home splits equally
     * between them, the individual assessment.
     *
     * @param  array<string, mixed>  $state
     */
    private function careAssessableCapital(Household $household, array $state, string $personId, int $aliveCount): int
    {
        $capital = ($state['cash'][$personId] ?? 0) + ($state['gia'][$personId] ?? 0) + ($state['isa'][$personId] ?? 0);

        $home = $household->primaryResidence;
        if ($home !== null && ! $state['homeSold'] && ($aliveCount === 1 || $home->isLet)) {
            $equity = max(0, $state['property'] - $state['mortgageOutstanding']);
            $capital += intdiv($equity, max(1, $aliveCount));
        }

        return $capital;
    }

    private function dbIncome(Household $household, string $pid, int $age, float $dbFactor): int
    {
        $total = 0;
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DbPension && $pension->ownerId === $pid && $age >= $pension->normalRetirementAge) {
                $total += (int) round($this->commutedAnnualPence($pension) * $dbFactor);
            }
        }

        return $total;
    }

    /**
     * The DB pension's annual amount in today's money after any commutation election. Taking a
     * tax-free lump sum permanently reduces the pension by lumpSum ÷ factor (the scheme's
     * £-per-£1-given-up ratio; null/≤0 defaults to 12). Used for both the member's own income and
     * the survivor fraction, so the survivor inherits a fraction of the reduced pension.
     */
    private function commutedAnnualPence(DbPension $pension): int
    {
        $accrued = $pension->accruedAnnualPension->pence;
        if ($pension->commutationLumpSum === null || $pension->commutationLumpSum->pence <= 0) {
            return $accrued;
        }
        $factor = ($pension->commutationFactor !== null && $pension->commutationFactor > 0)
            ? $pension->commutationFactor
            : 12.0;

        return max(0, $accrued - (int) round($pension->commutationLumpSum->pence / $factor));
    }

    /**
     * The DB commutation tax-free lump sum due this year, nominal pence. Paid once, in the forecast
     * year the member reaches normal retirement age while alive (age == NRA), escalated by dbFactor
     * so the £-for-£ commutation relationship holds at the actual retirement date. A member already
     * past NRA at the base year commuted before the forecast (their savings already reflect it), so
     * it is not re-paid — age never equals NRA for them. Tax-free (PCLS); the LSA cap is a v1 limit.
     */
    private function commutationLumpSumNominal(Household $household, string $pid, int $age, float $dbFactor): int
    {
        $total = 0;
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DbPension
                && $pension->ownerId === $pid
                && $pension->commutationLumpSum !== null
                && $pension->commutationLumpSum->pence > 0
                && $age === $pension->normalRetirementAge) {
                $total += (int) round($pension->commutationLumpSum->pence * $dbFactor);
            }
        }

        return $total;
    }

    private function statePensionIncome(Household $household, string $pid, int $calendarYear, int $spClaimYear, float $spFactor): int
    {
        // Nothing is paid before the claim year: at State Pension age if undeferred, later by the
        // deferral period if deferring — the forgone income is what makes deferral a genuine
        // trade-off (an early death after deferring is a net lifetime loss), not a free uplift.
        if ($calendarYear < $spClaimYear) {
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

    /**
     * Whole years of State Pension deferral for a person (0 if none): the deferral weeks on their
     * entitlement rounded to whole years, since the projection steps a year at a time. The claim
     * year is State Pension age plus this.
     */
    private function deferralYears(Household $household, string $pid): int
    {
        foreach ($household->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $pid && $pension->deferralWeeks > 0) {
                return (int) round($pension->deferralWeeks / $this->config->statePension->weeksPerYear);
            }
        }

        return 0;
    }

    /**
     * The person's notional undeferred State Pension for the Pension Credit assessable-income test
     * during their deferral window (State Pension age reached but the claim not yet started): 0
     * outside that window. DWP treats a deferred State Pension as income you could be receiving, so
     * deferring must not silently boost Pension Credit while the claim is paused. Uprated by the
     * running triple-lock factor, like the paid figure.
     */
    private function notionalDeferredStatePensionNominal(Household $household, string $pid, int $calendarYear, int $spaYear, int $spClaimYear, float $spFactor): int
    {
        if ($calendarYear < $spaYear || $calendarYear >= $spClaimYear) {
            return 0;
        }
        foreach ($household->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $pid) {
                $base = $pension->weeklyForecast !== null
                    ? $this->statePension->fromWeeklyForecast($pension->weeklyForecast)
                    : $this->statePension->fromQualifyingYears($pension->qualifyingYears ?? 0);

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
     * The household's rental income this year (nominal), across every living owner — the base for
     * the buy-to-let finance-cost tax reducer. Only {@see IncomeStreamType::Rental} streams count,
     * so a generic "other" income is not mistaken for rent.
     *
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     */
    private function rentalIncomeNominal(Household $household, array $alive, array $ages, float $cumInflation): int
    {
        $total = 0;
        foreach ($household->incomeStreams as $stream) {
            if ($stream->type !== IncomeStreamType::Rental || ! ($alive[$stream->ownerId] ?? false)) {
                continue;
            }
            $age = $ages[$stream->ownerId] ?? 0;
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

                if ($instruction->kind->triggersMpaa()) {
                    // Flexible access: from now on this member's money-purchase contributions are
                    // capped at the MPAA ({@see mpaaHeadroom}). One home for the trigger, so an
                    // ad-hoc UFPLS draw and a planned instruction cannot set it differently.
                    $state['mpaaTriggered'][$pid] = true;
                }

                switch ($instruction->kind) {
                    case WithdrawalKind::Ufpls:
                        [$tf, $tx] = self::ufplsSplit($amount, $lsaRemaining, $pclsRate);
                        $taxFree += $tf;
                        $taxable += $tx;
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
     * Split a gross UFPLS withdrawal into its tax-free and taxable parts: 25% is tax-free, but
     * only while the member's Lump Sum Allowance lasts, beyond which the whole withdrawal is
     * taxable. THE one home for the rule, so a planned WithdrawalInstruction and an ad-hoc
     * FillBands draw can never diverge (they used to be two copies of the same three lines).
     * Public static so the split is unit-tested directly at the allowance boundary.
     *
     * @return array{0: int, 1: int} [taxFree, taxable], both in pence
     */
    public static function ufplsSplit(int $gross, int $lsaRemaining, float $pclsRate): array
    {
        $taxFree = min((int) floor($gross * $pclsRate), max(0, $lsaRemaining));

        return [$taxFree, $gross - $taxFree];
    }

    /**
     * The largest gross UFPLS withdrawal whose TAXABLE part still fits in $taxableRoom (the
     * income the person can take before crossing the band being filled), given $lsaRemaining of
     * tax-free allowance left.
     *
     * Two caps bind at once and swap over: while the tax-free 25% is covered by the allowance the
     * taxable part is 75% of the draw (so room / 0.75 fits), and once the allowance runs out every
     * further pound is taxable (so room + allowance fits). The two agree exactly where they meet.
     * The final loop only ever costs one step: it repairs the penny that {@see ufplsSplit}'s floor
     * can add to the taxable part.
     */
    public static function maxUfplsGross(int $taxableRoom, int $lsaRemaining, float $pclsRate): int
    {
        if ($taxableRoom <= 0) {
            return 0;
        }
        $allowance = max(0, $lsaRemaining);
        $gross = (int) floor($taxableRoom / (1.0 - $pclsRate));
        if ((int) floor($gross * $pclsRate) > $allowance) {
            $gross = $taxableRoom + $allowance;
        }
        while ($gross > 0 && self::ufplsSplit($gross, $allowance, $pclsRate)[1] > $taxableRoom) {
            $gross--;
        }

        return $gross;
    }

    /**
     * Buy any annuities due this year: for each planned purchase whose annuitant has reached
     * its age and is alive, convert part of that person's DC pot(s) into a lifetime annuity.
     * The pot is reduced by the purchase amount (capped at what is there, drawn across the
     * owner's pots in order) and the annuity becomes active, paying baseIncome = amountBought
     * × rate from now on. Buying is not itself a taxable event; the income it pays is taxed as
     * it arrives (see annuityIncomeNominal). The purchase amount is treated as nominal at the
     * purchase age, matching planned withdrawals (a v1 simplification, flagged). Each purchase
     * fires once (the `purchased` flag), even if the pot cannot fund it — a dead annuitant, or
     * an empty pot, simply means no income.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function processAnnuityPurchases(array &$state, int $yearIndex, array $alive, float $cumInflation): void
    {
        foreach ($state['annuities'] as &$annuity) {
            if ($annuity['purchased']) {
                continue;
            }
            $pid = $annuity['ownerId'];
            $age = $state['baseAge'][$pid] + $yearIndex;
            if ($age < $annuity['atAge'] || ! ($alive[$pid] ?? false)) {
                continue;
            }

            $needed = $annuity['amount'];
            $bought = 0;
            foreach ($state['pots'][$pid] as &$pot) {
                if ($needed <= 0) {
                    break;
                }
                $take = min($needed, $pot['value']);
                $pot['value'] -= $take;
                $needed -= $take;
                $bought += $take;
            }
            unset($pot);

            $annuity['purchased'] = true;
            if ($bought > 0) {
                $annuity['active'] = true;
                $annuity['baseIncomeNominal'] = (int) round($bought * $annuity['rate']);
                $annuity['purchaseCumInflation'] = $cumInflation;
            }
        }
        unset($annuity);
    }

    /**
     * This year's annuity income, per person, in nominal pence. While the annuitant lives they
     * receive the full income (taxed on them); after they die a joint annuity continues at its
     * survivor fraction to the first living person (the surviving partner), while a single-life
     * annuity stops. A level annuity (escalation None) pays a flat nominal income that falls in
     * real terms; any other basis escalates the income with inflation since purchase — the same
     * proxy the engine uses for DB escalation in payment.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @return array<string, int> personId => nominal taxable annuity income
     */
    private function annuityIncomeNominal(array $state, Household $household, array $alive, float $cumInflation): array
    {
        $income = [];
        foreach ($state['annuities'] as $annuity) {
            if (! $annuity['active']) {
                continue;
            }

            $factor = $annuity['escalation'] === PensionEscalationBasis::None
                ? 1.0
                : $cumInflation / $annuity['purchaseCumInflation'];
            $amount = (int) round($annuity['baseIncomeNominal'] * $factor);
            if ($amount <= 0) {
                continue;
            }

            if ($alive[$annuity['ownerId']] ?? false) {
                $income[$annuity['ownerId']] = ($income[$annuity['ownerId']] ?? 0) + $amount;
            } elseif ($annuity['survivorFraction'] !== null) {
                $survivor = $this->firstLiving($household, $alive);
                if ($survivor !== null) {
                    $income[$survivor] = ($income[$survivor] ?? 0) + (int) round($amount * $annuity['survivorFraction']);
                }
            }
        }

        return $income;
    }

    /**
     * This year's survivor DB pension income, per person, in nominal pence. When a DB member has
     * died, a scheme with a spousePensionFraction continues that fraction of the pension to the
     * surviving partner for life (the joint-life analogue of {@see annuityIncomeNominal}). Escalated
     * by the same dbFactor as the member's own pension in payment. Without this the guaranteed DB
     * income would silently fall to £0 on the member's death, understating the survivor's secure income.
     *
     * A single-fraction v1 model: a scheme with no survivor fraction pays nothing (as today), and the
     * fraction is paid from the member's death regardless of whether they had reached normal retirement
     * age (real schemes pay a spouse's pension on death in service / deferment / payment alike).
     *
     * @param  array<string, bool>  $alive
     * @return array<string, int> personId => nominal taxable survivor DB income
     */
    private function survivorDbIncomeNominal(Household $household, array $alive, float $dbFactor): array
    {
        $income = [];
        foreach ($household->pensions as $pension) {
            if (! $pension instanceof DbPension || $pension->spousePensionFraction === null) {
                continue;
            }
            if ($alive[$pension->ownerId] ?? false) {
                continue; // member still alive — they draw their own full pension via dbIncome()
            }
            $survivor = $this->firstLiving($household, $alive);
            if ($survivor === null) {
                continue; // no surviving partner to inherit the income
            }
            $full = (int) round($this->commutedAnnualPence($pension) * $dbFactor);
            $income[$survivor] = ($income[$survivor] ?? 0)
                + (int) round($full * $pension->spousePensionFraction->asFraction());
        }

        return $income;
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
        $fraction = self::workFraction($person, $age);
        if ($fraction <= 0.0) {
            return 0;
        }

        $calendarYear = $state['baseYear'] + $yearIndex;
        $reachedSpa = $calendarYear >= $state['spaYear'][$pid];

        // NI on the actual (prorated in the retirement year) earnings; it ends at State Pension age.
        $earnings = (int) round($person->grossSalary->pence * $state['salaryFactor'][$person->id] * $fraction);

        return $this->ni->onEmploymentEarnings(Money::fromPence($earnings), hasReachedStatePensionAge: $reachedSpa, category: $person->niCategory)->total->pence;
    }

    /**
     * The fraction of the calendar year a person works: a full year before their planned
     * retirement age, zero after it, and a part-year in the year they reach it — they stop on
     * their birthday (when they turn that age), so the fraction is their birth month ÷ 12. The
     * final working year is therefore a real part-year of salary, not a dropped whole year.
     */
    private static function workFraction(Person $person, int $age): float
    {
        $retireAge = $person->plannedRetirementAge;
        if ($retireAge === null || $age < $retireAge) {
            return 1.0;
        }
        if ($age > $retireAge) {
            return 0.0;
        }

        return ((int) $person->dob->format('n')) / 12.0;
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
    private function fundShortfall(Household $household, ForecastSettings $settings, array &$state, array $alive, array $ages, array $taxablePerPerson, int $shortfall, float $thresholdFactor = 1.0, bool $onGuaranteeCredit = false, array $seedGains = []): array
    {
        $remaining = $shortfall;
        $funded = 0;
        $extraTax = 0;
        $fromPension = 0; // gross pension withdrawn to meet the shortfall
        $fromAssets = 0;  // capital drawn from cash/GIA/ISA
        // GIA gains realised this year by disposals, per person (feeds CGT below). Seeded with
        // any gains a year-0 purchase draw already realised ($seedGains, pence), so the AEA
        // headroom in drawGiaToAea accounts for them — shared once, never granted twice.
        $realisedGain = array_fill_keys(array_map(fn ($p): string => $p->id, $household->persons), 0);
        foreach ($seedGains as $pid => $gain) {
            $realisedGain[$pid] = ($realisedGain[$pid] ?? 0) + $gain;
        }

        $strategy = $settings->drawdownStrategy;
        $params = $this->config->incomeTax;
        $paLimit = $params->personalAllowance->pence;
        $basicLimit = $paLimit + $params->basicRateBand->pence;

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
                            [$gainSlice, $basisConsumed] = self::disposeGiaSlice(
                                $state['gia'][$person->id],
                                $state['giaBasis'][$person->id],
                                $take,
                            );
                            $realisedGain[$person->id] += $gainSlice;
                            $state['giaBasis'][$person->id] -= $basisConsumed;
                        }
                        $state[$bucket][$person->id] -= $take;
                        $remaining -= $take;
                        $funded += $take;
                        $fromAssets += $take;
                    }
                }
            }
        };

        // Draw cash + ISA only (tax-free capital), skipping the GIA (which can realise CGT).
        $drawTaxFreeCapital = function () use (&$state, &$remaining, &$funded, &$fromAssets, $alive, $household): void {
            foreach (['cash', 'isa'] as $bucket) {
                foreach ($household->persons as $person) {
                    if ($remaining <= 0) {
                        return;
                    }
                    if (! $alive[$person->id]) {
                        continue;
                    }
                    $take = min($remaining, $state[$bucket][$person->id]);
                    if ($take > 0) {
                        $state[$bucket][$person->id] -= $take;
                        $remaining -= $take;
                        $funded += $take;
                        $fromAssets += $take;
                    }
                }
            }
        };

        // Draw GIA only up to the point each person's realised gain reaches the CGT annual
        // exempt amount, so no CGT is due on this tranche (the free-gains band).
        $drawGiaToAea = function () use (&$state, &$remaining, &$funded, &$fromAssets, &$realisedGain, $alive, $household): void {
            $aea = $this->config->cgt->annualExemptAmount->pence;
            foreach ($household->persons as $person) {
                if ($remaining <= 0) {
                    return;
                }
                if (! $alive[$person->id]) {
                    continue;
                }
                $bal = $state['gia'][$person->id];
                $basis = $state['giaBasis'][$person->id];
                if ($bal <= 0) {
                    continue;
                }
                $headroom = max(0, $aea - $realisedGain[$person->id]);
                // The largest disposal whose realised gain stays within the headroom; a holding
                // with no gain (balance <= basis) can be drawn freely (no CGT either way).
                $maxTake = $bal > $basis ? (int) floor($headroom * $bal / ($bal - $basis)) : $bal;
                $take = min($remaining, $bal, $maxTake);
                if ($take > 0) {
                    [$gainSlice, $basisConsumed] = self::disposeGiaSlice($bal, $basis, $take);
                    $realisedGain[$person->id] += $gainSlice;
                    $state['giaBasis'][$person->id] -= $basisConsumed;
                    $state['gia'][$person->id] -= $take;
                    $remaining -= $take;
                    $funded += $take;
                    $fromAssets += $take;
                }
            }
        };

        // Draw taxable pension income, per person, capped so the person's taxable income does
        // not exceed $taxableLimit (null = uncapped). Grosses up so the after-tax cash meets
        // the remaining need.
        $drawPension = function (?int $taxableLimit) use (&$state, &$remaining, &$funded, &$extraTax, &$fromPension, $alive, $ages, $household, $taxablePerPerson, $thresholdFactor): void {
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
                    // A DC pot is not accessible until its owner reaches its earliest access age
                    // (normal minimum pension age — 55, rising to 57 from April 2028); an inherited
                    // pot carries age 0 (a beneficiary can draw it at any age). Before then a
                    // shortfall cannot legally be met from this pot — it falls to other sources.
                    if (($ages[$person->id] ?? 0) < ($pot['earliestAccessAge'] ?? 0)) {
                        continue;
                    }
                    $cap = $pot['value'];
                    if ($taxableLimit !== null) {
                        $cap = min($cap, max(0, $taxableLimit - $alreadyTaxable));
                    }
                    if ($cap <= 0) {
                        continue;
                    }
                    $gross = $this->grossUpPension($remaining, $alreadyTaxable, $cap, $thresholdFactor);
                    if ($gross <= 0) {
                        continue;
                    }
                    $taxDelta = $this->marginalTax($alreadyTaxable, $gross, $thresholdFactor);
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

        // The same draw, taken UFPLS-style: 25% of each withdrawal is tax-free (while the Lump
        // Sum Allowance lasts) and only the rest is taxable income. This is what a retiree
        // drawing ad-hoc from an uncrystallised pot actually does, and taxing 100% of it instead
        // mispriced pension wealth against every other asset. FillBands only: $drawPension stays
        // byte-identical for TaxEfficient / PensionAware and the HMRC worked examples.
        //
        // Two caps bind at once: the band being filled ($taxableLimit, which only the TAXABLE
        // part consumes, so the draw is ~a third larger for the same taxable income) and the
        // person's remaining Lump Sum Allowance. {@see maxUfplsGross} solves both. With no
        // allowance left the split is all-taxable, so this degrades exactly to $drawPension.
        $drawPensionUfpls = function (?int $taxableLimit) use (&$state, &$remaining, &$funded, &$extraTax, &$fromPension, $alive, $ages, $household, $taxablePerPerson, $thresholdFactor): void {
            $pclsRate = $this->config->pension->pclsRate->asFraction();
            $lsa = $this->config->pension->lumpSumAllowance->pence;

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
                    // The access-age gate, exactly as $drawPension applies it: a pot cannot be
                    // touched before its owner reaches its earliest access age, whatever order
                    // the fill planner would prefer. (DECISIONS 2026-07-02.)
                    if (($ages[$person->id] ?? 0) < ($pot['earliestAccessAge'] ?? 0)) {
                        continue;
                    }
                    $lsaRemaining = max(0, $lsa - $state['lsaUsed'][$person->id]);
                    $cap = $pot['value'];
                    if ($taxableLimit !== null) {
                        $cap = min($cap, self::maxUfplsGross($taxableLimit - $alreadyTaxable, $lsaRemaining, $pclsRate));
                    }
                    if ($cap <= 0) {
                        continue;
                    }

                    // Gross up so the after-tax cash meets the need, on the same iteration as
                    // grossUpPension, where only the taxable part carries tax.
                    $gross = $remaining;
                    for ($i = 0; $i < 8; $i++) {
                        $tax = $this->marginalTax($alreadyTaxable, self::ufplsSplit($gross, $lsaRemaining, $pclsRate)[1], $thresholdFactor);
                        $next = $remaining + $tax;
                        if (abs($next - $gross) <= 1) {
                            $gross = $next;
                            break;
                        }
                        $gross = $next;
                    }
                    $gross = min($gross, $cap);
                    if ($gross <= 0) {
                        continue;
                    }

                    [$taxFree, $taxablePart] = self::ufplsSplit($gross, $lsaRemaining, $pclsRate);
                    $taxDelta = $this->marginalTax($alreadyTaxable, $taxablePart, $thresholdFactor);
                    $net = $gross - $taxDelta;
                    $pot['value'] -= $gross;
                    $state['lsaUsed'][$person->id] += $taxFree;
                    // A UFPLS is flexible access: it caps this member's future money-purchase
                    // contributions at the MPAA ({@see mpaaHeadroom}).
                    $state['mpaaTriggered'][$person->id] = true;
                    $remaining -= $net;
                    $funded += $net;
                    $extraTax += $taxDelta;
                    $fromPension += $gross;
                    $alreadyTaxable += $taxablePart;
                }
                unset($pot);
            }
        };

        if ($strategy === DrawdownStrategy::FillBands) {
            // Fill each tax-free band before a taxed pound. A household on Guarantee Credit is
            // the exception: any pension income claws the credit back £-for-£, so for them draw
            // capital first and leave the pension (and the credit) intact.
            if (! $onGuaranteeCredit) {
                $drawPensionUfpls($paLimit);    // pension within the personal allowance (0% income tax)
            }
            $drawGiaToAea();                    // GIA gains within the CGT annual exempt amount (0% CGT)
            $drawTaxFreeCapital();              // cash + ISA (tax-free capital)
            if (! $onGuaranteeCredit) {
                $drawPensionUfpls($basicLimit); // pension within the basic-rate band (20%)
            }
            $drawNonPension();                  // remaining GIA (CGT on gains beyond the AEA)
            // Remaining pension - last resort. On Guarantee Credit this is the ONLY pension step,
            // and taking it UFPLS-style means a quarter of it arrives as tax-free CAPITAL, which
            // the means test disregards as income, so less of the credit is clawed back.
            $drawPensionUfpls(null);
        } elseif ($strategy === DrawdownStrategy::PensionAware) {
            $drawPension($basicLimit);   // pension up to the basic-rate band first
            $drawNonPension();
            $drawPension(null);          // then any remaining pension
        } else {
            $drawNonPension();
            $drawPension(null);          // pension only as a last resort
        }

        // CGT on the GIA gains realised funding this year's spend (computed before any
        // further drawing, so the small extra gain from funding the tax itself is not
        // re-taxed — a bounded v1 simplification). It is a real cost, so draw a little
        // more to pay it; that funding is not spend, so it is taken back out of $funded.
        // NOTE: $fromAssets / $fromPension deliberately KEEP the CGT-funding draw — that
        // capital genuinely left the pots, so the incomeBySource drawdown lines reflect the
        // true withdrawal and the year's money-in == money-out (income + capital drawn == tax
        // + met spend). Only $funded (spend actually met) excludes it, so it differs from the
        // drawdown sources by exactly the tax in a disposal year — pinned by a reconciliation
        // test. Do not "restore" the source totals here or the cashflow ladder stops balancing.
        $cgt = $this->capitalGainsTax($realisedGain, $taxablePerPerson, $alive);
        if ($seedGains !== []) {
            // The seed's own CGT was already charged up-front in projectYear; charge only the
            // increment the in-year disposals add on top of it (the AEA and the rate bands are
            // judged on the combined gain, so the increment is exact, never double-counted).
            $cgt -= $this->capitalGainsTax($seedGains, $taxablePerPerson, $alive);
        }
        if ($cgt > 0) {
            $extraTax += $cgt;
            $remaining = $cgt;
            $fundedBeforeCgt = $funded;
            $drawNonPension();
            if ($strategy === DrawdownStrategy::FillBands) {
                $drawPensionUfpls(null);
            } else {
                $drawPension(null);
            }
            $funded = $fundedBeforeCgt;
        }

        return ['funded' => $funded, 'extraTax' => $extraTax, 'fromPension' => $fromPension, 'fromAssets' => $fromAssets];
    }

    /**
     * Split a partial GIA disposal of $take (from a holding worth $balance with cost
     * $basis) into the realised gain and the cost basis it consumes. The gain is rounded
     * and the basis consumed is derived as the remainder, so gain + basisConsumed == $take
     * exactly — the cost basis can never drift across many partial disposals (round-of-sum
     * vs sum-of-rounds, applied to a tax figure where exact pence is the standard). Public
     * static so the conservation invariant is unit-tested directly.
     *
     * @return array{0: int, 1: int} [realisedGain, basisConsumed], both in pence
     */
    public static function disposeGiaSlice(int $balance, int $basis, int $take): array
    {
        $gain = (int) round(max(0, $balance - $basis) * $take / $balance);

        return [$gain, $take - $gain];
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
        $personalAllowance = $this->config->incomeTax->personalAllowance->pence;
        $basicRateBand = $this->config->incomeTax->basicRateBand->pence;

        $total = 0;
        foreach ($realisedGain as $pid => $gain) {
            if (! ($alive[$pid] ?? false)) {
                continue;
            }
            $total += self::cgtOnGain(
                $gain, $taxablePerPerson[$pid] ?? 0, $aea, $personalAllowance,
                $basicRateBand, $cgt->residentialBasicRate, $cgt->residentialHigherRate,
            );
        }

        return $total;
    }

    /**
     * CGT on one person's realised gain (pence). Gains stack ABOVE income, but the personal
     * allowance is NOT available against gains: only the basic-rate BAND left after income
     * *above* the PA fills at the lower rate, the rest at the higher rate. Crucially, when
     * income is below the PA the unused allowance must NOT extend the lower-rate band (else a
     * low-income retiree's gain is under-taxed). Public static so the band split is unit-tested
     * directly across the below-PA and straddle boundaries.
     */
    public static function cgtOnGain(int $gain, int $income, int $aea, int $personalAllowance, int $basicRateBand, Percent $basicRate, Percent $higherRate): int
    {
        $chargeable = max(0, $gain - $aea);
        if ($chargeable <= 0) {
            return 0;
        }
        $basicRoom = max(0, $basicRateBand - max(0, $income - $personalAllowance));
        $atBasic = min($chargeable, $basicRoom);
        $atHigher = $chargeable - $atBasic;

        return Money::fromPence($atBasic)->applyRate($basicRate)->pence
            + Money::fromPence($atHigher)->applyRate($higherRate)->pence;
    }

    /**
     * Gross pension withdrawal whose after-tax value meets $netNeeded, given the
     * person's existing taxable income, capped at $maxGross. Iterates to convergence
     * (income tax is piecewise linear, so this is exact within a few rounds).
     */
    private function grossUpPension(int $netNeeded, int $existingTaxable, int $maxGross, float $thresholdFactor = 1.0): int
    {
        $gross = $netNeeded;
        for ($i = 0; $i < 8; $i++) {
            $tax = $this->marginalTax($existingTaxable, $gross, $thresholdFactor);
            $next = $netNeeded + $tax;
            if (abs($next - $gross) <= 1) {
                $gross = $next;
                break;
            }
            $gross = $next;
        }

        return min($gross, $maxGross);
    }

    private function marginalTax(int $existingTaxable, int $extra, float $thresholdFactor = 1.0): int
    {
        $base = $this->indexedTotalPence(TaxableIncome::ofNonSavings(Money::fromPence($existingTaxable)), $thresholdFactor);
        $with = $this->indexedTotalPence(TaxableIncome::ofNonSavings(Money::fromPence($existingTaxable + $extra)), $thresholdFactor);

        return $with - $base;
    }

    /**
     * Income tax due, in pence, under income-tax thresholds indexed for inflation since the
     * freeze ended. The income-tax function is homogeneous of degree 1 in (income, all of its
     * monetary thresholds), so taxing income deflated to the freeze-end price level against the
     * frozen base-year thresholds and re-inflating the result equals tax under the inflated
     * thresholds — without rebuilding the band config in the 10k-path hot loop. $factor == 1.0
     * (during the freeze, and for the HMRC worked-example unit tests) is the exact identity.
     */
    private function indexedTotalPence(TaxableIncome $income, float $factor): int
    {
        if ($factor <= 1.0) {
            return $this->incomeTax->totalPence($income);
        }

        $deflated = new TaxableIncome(
            Money::fromPence((int) round($income->nonSavings->pence / $factor)),
            Money::fromPence((int) round($income->savings->pence / $factor)),
            Money::fromPence((int) round($income->dividends->pence / $factor)),
        );

        return (int) round($this->incomeTax->totalPence($deflated) * $factor);
    }

    private function oneOffCostsNominal(Household $household, array $ages, float $cumInflation): int
    {
        // v1 limitation (flagged): a one-off cost has an `atAge` but no `personId`, so it fires on
        // the FIRST-declared person's age only. A cost meant to land at the second person's age
        // cannot trigger, and (since $ages carries dead persons too) the reference age keeps
        // advancing after that person dies. Add a per-cost personId to lift this.
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
     * stops automatically once income no longer covers spend.
     *
     * Handles only what the household actually pays for out of its own money: regular
     * account savings, and member pension contributions under a scheme whose relief
     * method is not net pay. Employer contributions and net-pay member contributions
     * are paid earlier, in the earnings step, because neither reaches household cash.
     * A pension with NO relief method set keeps the pre-2026-07-31 behaviour (paid from
     * net surplus, no relief), which is why an unset method is raised as an input note.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    /**
     * Pay the employer's contributions into this person's pots for the year. The employer's money
     * never passes through the household's cashflow, so it is credited outright rather than
     * competing with the household's spending for surplus; it is prorated by the fraction of the
     * year actually worked and stops when the job does.
     *
     * @param  array<string, mixed>  $state
     */
    private function payEmployerContributions(array &$state, string $pid, float $spendFactor, float $workFraction): void
    {
        if ($workFraction <= 0.0) {
            return;
        }
        foreach ($state['pots'][$pid] as &$pot) {
            $this->payIntoPot($state, $pid, $pot, (int) round(($pot['employerContribution'] ?? 0) * $spendFactor * $workFraction));
        }
        unset($pot);
    }

    /**
     * This member's remaining money-purchase contribution allowance for the year. Unlimited
     * until they flexibly access a pension (a UFPLS or drawdown income, planned or drawn to fund
     * a shortfall); from then on it is the Money Purchase Annual Allowance less what has already
     * gone in this year: the rule that stops a plan drawing a pot down in the free bands and
     * recycling the cash straight back in.
     *
     * v1 simplifications, both flagged: the allowance is modelled as a hard cap on what may be
     * paid in rather than as an annual-allowance CHARGE on the excess ({@see AnnualAllowanceCalculator}
     * prices that separately), and it bites from the year of the trigger rather than the day
     * after it. It is the frozen statutory figure, not indexed, because nothing has indexed it.
     *
     * @param  array<string, mixed>  $state
     */
    private function mpaaHeadroom(array $state, string $pid): int
    {
        if (! ($state['mpaaTriggered'][$pid] ?? false)) {
            return PHP_INT_MAX;
        }

        return max(0, $this->config->pension->moneyPurchaseAnnualAllowance->pence - ($state['mpContributed'][$pid] ?? 0));
    }

    /**
     * Pay $amount into one of this member's money-purchase pots, capped at their remaining MPAA
     * headroom, and return what actually went in. THE one place a DC pot is credited with a
     * contribution, so the cap cannot be applied at two of the three sites and missed at the
     * third, and so the year's running total has one home.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $pot
     */
    private function payIntoPot(array &$state, string $pid, array &$pot, int $amount): int
    {
        $give = min(max(0, $amount), $this->mpaaHeadroom($state, $pid));
        if ($give <= 0) {
            return 0;
        }
        $pot['value'] += $give;
        $state['mpContributed'][$pid] = ($state['mpContributed'][$pid] ?? 0) + $give;

        return $give;
    }

    /**
     * Pay this person's own NET-PAY contributions into their pots for the year and return the
     * total, which the caller subtracts from gross earnings — that subtraction IS the tax relief.
     *
     * Capped at $earnings in aggregate: a net-pay contribution is taken from pay, so it cannot
     * exceed pay (which is also the statutory limit on relievable contributions for an earner,
     * and the reason a retired member's net-pay contribution is zero rather than continuing for
     * ever). Pots are paid in declaration order and the cap bites on the later ones.
     *
     * @param  array<string, mixed>  $state
     */
    private function payNetPayContributions(array &$state, string $pid, float $spendFactor, float $workFraction, int $earnings): int
    {
        if ($workFraction <= 0.0 || $earnings <= 0) {
            return 0;
        }

        $paid = 0;
        foreach ($state['pots'][$pid] as &$pot) {
            if (($pot['reliefMethod'] ?? null) !== PensionReliefMethod::NetPay) {
                continue;
            }
            $wanted = (int) round(($pot['contribution'] ?? 0) * $spendFactor * $workFraction);
            // What the MPAA blocks is never given up, so it stays in pay and is taxed there:
            // the caller subtracts only what actually reached the pot.
            $paid += $this->payIntoPot($state, $pid, $pot, max(0, min($wanted, $earnings - $paid)));
        }
        unset($pot);

        return $paid;
    }

    private function applyContributions(Household $household, array &$state, array $alive, float $spendFactor, int $surplus): int
    {
        $available = $surplus;
        $contributed = 0;

        $take = function (int $annualPence, int $capNominal = PHP_INT_MAX) use (&$available, &$contributed, $spendFactor): int {
            if ($annualPence <= 0 || $available <= 0) {
                return 0;
            }
            $give = min((int) round($annualPence * $spendFactor), $available, $capNominal);
            if ($give <= 0) {
                return 0;
            }
            $available -= $give;
            $contributed += $give;

            return $give;
        };

        // The member's OWN DC contributions, for schemes where they are genuinely paid out of the
        // household's money. A net-pay contribution is not: it is deducted from gross pay before
        // the household ever sees it (see payNetPayContributions), so taking it from surplus here
        // as well would charge it twice. The employer's contribution is likewise not the
        // household's to fund — see payEmployerContributions.
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            foreach ($state['pots'][$person->id] as &$pot) {
                if (($pot['reliefMethod'] ?? null) === PensionReliefMethod::NetPay) {
                    continue;
                }
                // Capped BEFORE the surplus is consumed, so what the MPAA blocks is not
                // silently dropped: it stays in the surplus and is saved into cash instead.
                $this->payIntoPot($state, $person->id, $pot, $take($pot['contribution'] ?? 0, $this->mpaaHeadroom($state, $person->id)));
            }
            unset($pot);
        }

        // Regular savings into accounts, added to the matching liquid bucket.
        //
        // ISA subscriptions are capped at the statutory overall allowance PER PERSON, PER YEAR
        // (£20,000; {@see IsaParameters}). Without the cap the model could shelter any amount of
        // income from tax for ever, which is not a rule the law has. The cap applies to money paid
        // IN, never to what the wrapper already holds — a pot that GREW past the allowance inside
        // an ISA is entirely legitimate and untouched here.
        //
        // Anything over the allowance SPILLS to that person's general investment account rather
        // than being dropped: the household still saves the money, it just saves it somewhere
        // taxable, which is what would happen in reality. Silently discarding it would breach the
        // completeness rule (an input that should count, not counting).
        $isaAllowance = $this->config->isa->overallAllowance->pence;
        $isaSubscribed = [];
        foreach ($household->accounts as $account) {
            if ($account->ongoingContributions === null || ! ($alive[$account->ownerId] ?? false)) {
                continue;
            }
            $owner = $account->ownerId;
            $added = $take($account->ongoingContributions->pence);
            if ($added <= 0) {
                continue;
            }

            $bucket = match ($account->type) {
                AccountType::Cash, AccountType::PremiumBonds => 'cash',
                AccountType::Gia => 'gia',
                AccountType::Isa => 'isa',
            };

            if ($account->type === AccountType::Isa) {
                $headroom = max(0, $isaAllowance - ($isaSubscribed[$owner] ?? 0));
                $intoIsa = min($added, $headroom);
                $isaSubscribed[$owner] = ($isaSubscribed[$owner] ?? 0) + $intoIsa;
                $state['isa'][$owner] += $intoIsa;

                // The overflow is still saved, but in a taxable account.
                $spill = $added - $intoIsa;
                if ($spill > 0) {
                    $state['gia'][$owner] += $spill;
                    $state['giaBasis'][$owner] += $spill;
                }

                continue;
            }

            $state[$bucket][$owner] += $added;
            // New money into a GIA raises its cost basis, so only later growth is a gain.
            if ($account->type === AccountType::Gia) {
                $state['giaBasis'][$owner] += $added;
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
     * Whether anyone in the household is still earning a salary this year — the same
     * condition that produces employment earnings (employed, alive, before the planned
     * retirement age), and so the condition that keeps employment-linked costs (e.g.
     * commuting) charged. v1 simplification: the cost stops when the *last* earner retires
     * (it is not tied to a specific commuter).
     *
     * @param  array<string, bool>  $alive
     * @param  array<string, mixed>  $state
     */
    private function anyoneWorking(Household $household, array $alive, array $state, int $yearIndex): bool
    {
        foreach ($household->persons as $person) {
            $age = $state['baseAge'][$person->id] + $yearIndex;
            if (($alive[$person->id] ?? false)
                && $person->employmentStatus === EmploymentStatus::Employed
                && $person->grossSalary !== null
                && ($person->plannedRetirementAge === null || $age < $person->plannedRetirementAge)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grow nominal balances and income factors to the start of the next year.
     *
     * @param  array<string, mixed>  $state
     */
    /** Advance balances and growth factors to the start of next year. Returns the year's nominal
     *  ['growth' => capital appreciation left in the invested pots, GROSS of charges — the untaxed
     *  part of the return, separate from the interest/dividends projectYear pays out as income;
     *  'charges' => the ongoing platform/fund charge taken out of those pots]. Reported as a pair
     *  so opening balance + growth - charges reconciles to the closing balance, and so the charge
     *  is a figure the reader can see rather than a silently smaller growth line.
     *
     *  @return array{growth: int, charges: int} */
    private function growState(array &$state, PathDraws $draws, int $yearIndex): array
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
        $globalGiaYield = $draws->investmentIncomeYield(); // per-person effective yield blends overrides
        $cashCapital = $cashNominal - max(0.0, $cashNominal);

        // The ongoing charge (platform + fund OCF) INVESTED balances bear, taken out of the pot
        // after this year's growth. Asset-class returns are quoted gross of charges, so without
        // this the household holds its portfolio for free. Cash deposits are not charged (a bank
        // account has no platform or fund fee) and neither is the home. Clamped so a nonsense
        // rate can never pay money IN or wipe a balance out.
        $chargeRate = min(1.0, max(0.0, $draws->investmentChargeRate()));
        $charges = 0; // the pounds those charges took out of the pots this year

        $charged = static function (int $grown) use ($chargeRate, &$charges): int {
            if ($chargeRate <= 0.0 || $grown <= 0) {
                return $grown;
            }
            $fee = (int) round($grown * $chargeRate);
            $charges += $fee;

            return $grown - $fee;
        };

        $growth = 0; // capital appreciation left in the pots (grown balance − old), GROSS of charges
        foreach ($state['cash'] as $pid => $v) {
            $before = $v + $state['gia'][$pid] + $state['isa'][$pid];
            foreach ($state['pots'][$pid] as $pot) {
                $before += $pot['value'];
            }

            // GIA grows at capital only = total return minus THIS person's effective income yield
            // (blending any per-account override), mirroring the income paid out in projectYear.
            $giaCapital = $investNominal - $this->effectiveGiaYield($state, $pid, $globalGiaYield);
            $cashGrown = (int) round($v * (1.0 + $cashCapital));
            $giaGrown = (int) round($state['gia'][$pid] * (1.0 + $giaCapital));
            $isaGrown = (int) round($state['isa'][$pid] * (1.0 + $investNominal));
            $state['cash'][$pid] = $cashGrown; // cash bears no ongoing charge
            $state['gia'][$pid] = $charged($giaGrown);
            $state['isa'][$pid] = $charged($isaGrown);
            $grown = $cashGrown + $giaGrown + $isaGrown;
            foreach ($state['pots'][$pid] as &$pot) {
                // A per-pot growth override grows that pot at its own real rate; otherwise the
                // blended investment return. (The override sets return, not risk — no volatility.)
                $potNominal = $pot['growthOverrideReal'] !== null
                    ? (1.0 + $pot['growthOverrideReal']) * (1.0 + $infl) - 1.0
                    : $investNominal;
                $potGrown = (int) round($pot['value'] * (1.0 + $potNominal));
                $grown += $potGrown;
                $pot['value'] = $charged($potGrown);
            }
            unset($pot);

            $growth += $grown - $before;
        }

        // A per-property growth override grows the home at its own real rate; otherwise the
        // assumption-set house-price growth.
        $propertyNominal = $state['propertyGrowthReal'] !== null
            ? (1.0 + $state['propertyGrowthReal']) * (1.0 + $infl) - 1.0
            : $houseNominal;
        $state['property'] = (int) round($state['property'] * (1.0 + $propertyNominal));
        // The whole-property value tracks the same growth, so a forced sale reads the grown
        // whole figure for its CGT gain (share value / share, without the rounding drift).
        $state['propertyWhole'] = (int) round($state['propertyWhole'] * (1.0 + $propertyNominal));

        // A lifetime mortgage (equity release) rolls up: with no payments the balance compounds
        // at its fixed nominal rate each year. It is repaid from the estate on death/sale, capped
        // at the home's value by the No-Negative-Equity Guarantee — so cap the (share-scaled)
        // balance at the (share-scaled) home value. A null rate leaves the balance static (a
        // repayment/interest-serviced mortgage, whose interest is an expense line, not accrued).
        if ($state['mortgageRollUpRate'] !== null && $state['mortgageOutstanding'] > 0) {
            $rolled = (int) round($state['mortgageOutstanding'] * (1.0 + $state['mortgageRollUpRate']));
            // A voluntary overpayment pays some of the (grown) balance back down each year, slowing
            // the roll-up. Fixed nominal, floored at zero; the cash for it is the Mortgage expense line.
            $state['mortgageOutstanding'] = max(0, min($rolled, $state['property']) - $state['mortgageOverpaymentAnnual']);
        }

        // A capital-and-interest mortgage instead AMORTISES: next year opens on whatever the
        // schedule says is left after this year's instalments, reaching zero at the end of the
        // term. Read, never accrued — the schedule is the single definition of the balance. A
        // mortgage already cleared (redeemed early, or the home sold) stays cleared.
        if ($state['repaymentSchedule'] !== null && ! $state['mortgageRepaid'] && ! $state['homeSold']) {
            $state['mortgageOutstanding'] = (int) round(
                $state['repaymentSchedule']->openingBalanceIn($state['baseYear'] + $yearIndex + 1)->pence
                    * $state['ownershipShare']
            );
        }

        $rentNominal = (1.0 + $state['rentInflationReal']) * (1.0 + $infl) - 1.0;

        // Each person's salary escalates at their own real growth override, else the assumption-set
        // rate. (The override sets the trend, not risk — no volatility, mirroring the per-pot override.)
        foreach ($state['salaryFactor'] as $pid => $factor) {
            $personSalaryNominal = $state['salaryGrowthReal'][$pid] !== null
                ? (1.0 + $state['salaryGrowthReal'][$pid]) * (1.0 + $infl) - 1.0
                : $salaryNominal;
            $state['salaryFactor'][$pid] = $factor * (1.0 + $personSalaryNominal);
        }
        $state['dbFactor'] *= (1.0 + $this->dbEscalation($infl));
        $state['spFactor'] *= (1.0 + max($infl, 0.025)); // triple-lock proxy
        $state['spendFactor'] *= (1.0 + $infl);
        $state['rentFactor'] *= (1.0 + $rentNominal);

        return ['growth' => $growth, 'charges' => $charges];
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
