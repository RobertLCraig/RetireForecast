<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use InvalidArgumentException;
use LogicException;
use RetireForecast\FinanceEngine\Benefits\CapitalAssessment;
use RetireForecast\FinanceEngine\Benefits\CouncilTax;
use RetireForecast\FinanceEngine\Benefits\Deprivation;
use RetireForecast\FinanceEngine\Benefits\DisabilityBenefitInCare;
use RetireForecast\FinanceEngine\Benefits\HousingBenefit;
use RetireForecast\FinanceEngine\Benefits\PensionCreditCalculator;
use RetireForecast\FinanceEngine\Benefits\PensionCreditResult;
use RetireForecast\FinanceEngine\Benefits\SupportForMortgageInterest;
use RetireForecast\FinanceEngine\Care\CareMeansTest;
use RetireForecast\FinanceEngine\Care\DeferredPaymentAgreement;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
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
use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Housing\Tenancy;
use RetireForecast\FinanceEngine\Iht\EstateValuation;
use RetireForecast\FinanceEngine\Iht\EstateValuer;
use RetireForecast\FinanceEngine\Iht\IhtOutcome;
use RetireForecast\FinanceEngine\Iht\IhtResult;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\PenceSplit;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Pension\AnnualAllowanceCalculator;
use RetireForecast\FinanceEngine\Pension\PurchasedLifeAnnuity;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;
use RetireForecast\FinanceEngine\StatePension\StatePensionCalculator;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\Tax\ChattelsGain;
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
 *  - DB revaluation and escalation are per-scheme annual growth factors, each switching
 *    from the scheme's revaluation basis to its in-payment basis at normal retirement
 *    age ({@see escalateDbPensions}); the State Pension triple lock is one smooth factor.
 */
final class PathProjector
{
    /**
     * The tax year unused pension pots start counting towards the estate for Inheritance Tax
     * (the enacted April-2027 rule, Finance Act 2026). A death before then excludes pensions.
     */
    private const PENSIONS_IN_ESTATE_FROM_YEAR = 2027;

    /**
     * How many years the projection loop may run before it treats itself as broken. The loop ends
     * on the last death and mortality caps at 110, so no real path comes close; passing this means
     * the death ages handed in are wrong, which is a fault to surface rather than a length to cap.
     */
    private const MAX_PROJECTION_YEARS = 200;

    /**
     * The slice of extra pension income the projected MARGINAL rate is measured over, for netting a
     * pension pot down to what it is worth to spend ({@see pensionTaxIfDrawn}). £1,000 rather than a
     * penny: the marginal charge is the difference of two whole-income computations, each rounded,
     * so a probe of a few pence is swamped by that rounding once inflation-indexed thresholds are in
     * play. Large enough to be exact, small enough that it rarely straddles a band boundary — and
     * where it does, the blend it returns is the honest answer for the pound after it.
     */
    private const MARGINAL_RATE_PROBE_PENCE = 100_000;

    /**
     * How many times a year may re-solve the Pension Credit award against the pension draw that
     * award decides the size of (board card 0077). The secant step lands on the answer in three
     * passes for the ordinary shape; the rest is headroom for a year whose draw crosses a tax
     * band or a strategy's Guarantee-Credit branch and moves the line the step is drawn through.
     */
    private const MAX_PENSION_CREDIT_PASSES = 8;

    /**
     * What a mixed-age couple is told in a year the qualifying-age gate blocks Pension Credit.
     * One home for the copy, so the message and the rule that raises it cannot drift apart
     * ({@see WarningCode::MIXED_AGE_COUPLE}, board card 0051). It states the rule and names the
     * replacement; it does not tell the reader to claim anything.
     */
    private const MIXED_AGE_COUPLE_MESSAGE = 'One partner is under State Pension age, so this household cannot claim Pension Credit '
        .'at all this year: a mixed-age couple is treated as working age until the younger partner reaches State Pension age. '
        .'Working-age support applies instead: Universal Credit, whose housing element takes the place of Housing Benefit, '
        .'assessed on different rules, on the couple\'s joint income and capital, and commonly worth thousands of pounds a year '
        .'less than Pension Credit would be. This forecast models Pension Credit only, so it shows no means-tested benefit in '
        .'these years even where Universal Credit would be payable, and the shortfall shown here is the more pessimistic of the two.';

    private readonly IncomeTaxCalculator $incomeTax;

    private readonly NationalInsuranceCalculator $ni;

    private readonly StatePensionCalculator $statePension;

    private readonly PensionCreditCalculator $pensionCredit;

    private readonly CapitalAssessment $capitalAssessment;

    private readonly InheritanceTaxCalculator $iht;

    private readonly CareMeansTest $careMeans;

    private readonly AnnualAllowanceCalculator $annualAllowance;

    /** Built on first use only: no path that buys no purchased life annuity ever needs it. */
    private ?CohortLifeTable $lifeTable = null;

    public function __construct(private readonly TaxYearConfig $config)
    {
        $this->incomeTax = new IncomeTaxCalculator($config);
        $this->ni = new NationalInsuranceCalculator($config);
        $this->statePension = new StatePensionCalculator($config);
        $this->pensionCredit = new PensionCreditCalculator($config);
        $this->capitalAssessment = new CapitalAssessment($config);
        $this->iht = new InheritanceTaxCalculator($config);
        $this->careMeans = new CareMeansTest($config);
        $this->annualAllowance = new AnnualAllowanceCalculator($config);
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
                        $this->recordFirstDeathIht($state, $household, $settings, $draws, $person, $cumInflation);
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

            // Safety backstop. Mortality caps at 110, so the death check above always ends the loop
            // long before this: reaching it means the death ages handed in are not ages, and the
            // projection is wrong rather than long. It used to `break`, which returned the years it
            // had managed as though they were the whole projection, so the terminal wealth, the
            // depletion year and the IHT on the final death were all read off a truncation nobody
            // was told about.
            if ($yearIndex > self::MAX_PROJECTION_YEARS) {
                throw new LogicException(
                    'Projection passed its '.self::MAX_PROJECTION_YEARS.'-year backstop at '
                    .$calendarYear.': every person is still alive past age 200, so the death ages '
                    .'given to this path are not ages.'
                );
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
            terminalUsableWealth: $terminal ? $terminal->usableWealth() : Money::zero(),
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
        $beneficiaryIncomeTax = $second->beneficiaryIncomeTax
            ->plus($first?->beneficiaryIncomeTax ?? Money::zero());

        return new IhtOutcome($first, $second, $total, $beneficiaryIncomeTax);
    }

    /**
     * Record the first death's IHT: the deceased's OWN estate — their per-person liquid (cash +
     * ISA + GIA) and pension, plus their share of the home. The couple own the home jointly, so a
     * first death carries half the household's equity (a v1 50/50 split; immaterial for a married
     * couple, whose first death is spousally exempt only where there is a will). On the first death
     * the estate passes to the surviving partner, not to descendants, so the residence nil-rate
     * band never applies here.
     *
     * WHAT the survivor actually takes is the calculator's question, not this one's: it needs the
     * deceased's will, the survivor's residence position, and whether there are children to take a
     * share under intestacy. The engine holds no list of children, so `homeToDescendants` — the
     * reader's own statement that the home is left to direct descendants — is what says there are
     * issue to inherit. A plan that leaves nothing to descendants has a spouse who takes the whole
     * intestate estate, which is the statutory answer where there is no issue.
     *
     * @param  array<string, mixed>  $state
     */
    private function recordFirstDeathIht(array &$state, Household $household, ForecastSettings $settings, PathDraws $draws, Person $deceased, float $cumInflation): void
    {
        $married = $household->relationshipStatus() === RelationshipStatus::MarriedOrCivilPartnership;

        $liquid = Money::fromPence($state['cash'][$deceased->id] + $state['gia'][$deceased->id] + $state['isa'][$deceased->id]);
        $pension = Money::fromPence($this->personPots($state, $deceased->id));
        // The deceased's OWN beneficial share of the home, not half by assumption (board card
        // 0059). Null shares are equal shares, which reproduces the old split exactly, and the
        // equal default is disclosed rather than silent.
        $share = $household->primaryResidence?->beneficialShare($deceased->id, count($household->persons))
            ?? Percent::fromBasisPoints(5_000);
        $estate = EstateValuer::value(
            $liquid,
            $pension,
            $this->netHomeValue($household, $state)->applyRate($share),
            // Every charge on the home: the mortgage, any Support for Mortgage Interest loan and
            // any deferred care payment, each of which falls due on death exactly as it would on a
            // sale. The deceased carries their own share of each, the same share as the home.
            Money::fromPence($state['mortgageOutstanding'] + $state['smiBalance'] + $state['deferredCareBalance'])->applyRate($share),
        );

        $deathYear = (int) $deceased->dob->format('Y') + $draws->deathAge($deceased->id);
        $survivor = null;
        foreach ($household->persons as $person) {
            if ($person->id !== $deceased->id) {
                $survivor = $person;
            }
        }

        $result = $this->iht->computeFirstDeath(
            $estate->estateExcludingPensions,
            $estate->pensionValue,
            $deathYear >= self::PENSIONS_IN_ESTATE_FROM_YEAR,
            spouseSurvives: $married && $survivor !== null,
            deceasedLeftAWill: $deceased->hasWill,
            issueTakeUnderIntestacy: $settings->homeToDescendants,
            survivorIsUkLongTermResident: $survivor?->isUkLongTermResident() ?? true,
            // A pension death benefit follows the member's expression of wish, not the will, so
            // the exemption on it is decided pot by pot rather than by marital status.
            pensionNominatedToSpouse: Money::fromPence($this->personPotsNominatedToSpouse($state, $deceased->id)),
            deceasedDiedAtOrAfter75: $draws->deathAge($deceased->id) >= InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE,
            beneficiaryMarginalRate: $settings->beneficiaryMarginalRate(),
        );

        // The band this death CONSUMED, kept in NOMINAL pounds because that is the unit the frozen
        // band is set in and the unit the second death subtracts it in. The stored result below is
        // deflated to real for reporting, so it cannot serve here.
        $state['ihtNrbUsedAtFirstDeath'] = $result->nilRateBandUsed;
        $state['ihtFirstDeath'] = $this->deflateIht($result, 1.0 / $cumInflation);
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
        $married = $twoPeople && $household->relationshipStatus() === RelationshipStatus::MarriedOrCivilPartnership;

        $liquid = Money::fromPence($this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']));
        $estate = EstateValuer::value(
            $liquid,
            Money::fromPence($this->totalPots($state)),
            $this->netHomeValue($household, $state),
            // Everything secured on the home falls due here: the mortgage, any Support for
            // Mortgage Interest charge and any deferred care payment, both of the last two repaid
            // on the final death out of the same equity.
            Money::fromPence($state['mortgageOutstanding'] + $state['smiBalance'] + $state['deferredCareBalance']),
        );

        // The final death year is the last survivor's (the latest modelled death among those alive
        // in the final living year), used for the April-2027 pensions-in-estate gate.
        $deathYear = $settings->baseYear;
        // The last survivor's age at death, alongside the year: it is the survivor's own age, not
        // the household's oldest, that decides whether the pot they leave is taxable on whoever
        // inherits it.
        $deathAge = 0;
        foreach ($household->persons as $person) {
            if ($prevAlive[$person->id] ?? false) {
                $thisDeathYear = (int) $person->dob->format('Y') + $draws->deathAge($person->id);
                if ($thisDeathYear >= $deathYear) {
                    $deathAge = $draws->deathAge($person->id);
                }
                $deathYear = max($deathYear, $thisDeathYear);
            }
        }

        // The downsizing addition rides on the SAME condition as the residence band itself: it
        // restores what the band would have sheltered, and the band is only in play where the
        // estate passes to direct descendants. A plan leaving nothing to children gets neither.
        $disposal = $settings->homeToDescendants ? ($state['residenceDisposal'] ?? null) : null;

        // Only a MARRIED couple transfer a band, so only they can have spent part of it at the
        // first death. A cohabiting couple's second death already has one band of its own, and
        // subtracting the first death's use would charge them for the same band twice.
        $spent = $married ? ($state['ihtNrbUsedAtFirstDeath'] ?? null) : null;

        // A park home is a chattel on somebody else's pitch, not an interest in a dwelling-house,
        // so no residence nil-rate band is claimed against it (board card 0059). The home is still
        // in the estate at its value; it simply shelters nothing.
        $bandable = $settings->homeToDescendants
            && ($household->primaryResidence?->qualifiesForRnrb() ?? true);

        $state['ihtSecondDeath'] = $this->computeDeathIht($estate, multiplier: $married ? 2 : 1, homeToDescendants: $bandable, deathYear: $deathYear, cumInflation: $cumInflation, formerResidenceDisposal: $disposal, nilRateBandUsedAtFirstDeath: $spent, deathAge: $deathAge, beneficiaryMarginalRate: $settings->beneficiaryMarginalRate());
    }

    /**
     * The home's value NET of the site owner's commission on a resale
     * ({@see Property::netOfSaleCommission}), which is the one home of that arithmetic. An ordinary
     * house pays no commission and is untouched; a park home's value was overstated by the whole
     * tenth the site owner takes, and the exit is not optional, so both the estate and the care
     * means test read it through here (board card 0059).
     *
     * @param  array<string, mixed>  $state
     */
    private function netHomeValue(Household $household, array $state): Money
    {
        $gross = Money::fromPence($state['property']);

        return $household->primaryResidence?->netOfSaleCommission($gross) ?? $gross;
    }

    /**
     * Compute one death's IHT in NOMINAL pounds at the death year — so the frozen nil-rate bands
     * bite against the grown nominal estate (real fiscal drag, matching how the projector treats
     * frozen income-tax thresholds) — then deflate the whole result to REAL today's money for the
     * outcome. Unused pension pots enter the estate only from April 2027 (the enacted rule). The
     * FIRST death has its own path ({@see recordFirstDeathIht}), because what the survivor takes
     * depends on the will, the intestacy rules and the survivor's residence position.
     */
    private function computeDeathIht(EstateValuation $estate, int $multiplier, bool $homeToDescendants, int $deathYear, float $cumInflation, ?ResidenceDisposal $formerResidenceDisposal = null, ?Money $nilRateBandUsedAtFirstDeath = null, int $deathAge = 0, ?Percent $beneficiaryMarginalRate = null): IhtResult
    {
        $includePensions = $deathYear >= self::PENSIONS_IN_ESTATE_FROM_YEAR;
        $homeToDesc = $homeToDescendants ? $estate->homeEquity : Money::zero();

        return $this->deflateIht(
            $this->iht->compute(
                $estate->estateExcludingPensions,
                $estate->pensionValue,
                $includePensions,
                $homeToDesc,
                $multiplier,
                $formerResidenceDisposal,
                $nilRateBandUsedAtFirstDeath,
                $deathAge >= InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE,
                $beneficiaryMarginalRate,
            ),
            1.0 / $cumInflation,
        );
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
            downsizingAddition: $r($result->downsizingAddition),
            unusedPensionPassing: $r($result->unusedPensionPassing),
            beneficiaryIncomeTax: $r($result->beneficiaryIncomeTax),
            beneficiaryMarginalRate: $result->beneficiaryMarginalRate,
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
     * The part of one person's DC pension pots NOMINATED to their spouse or civil partner (nominal
     * pence): the only part a first death's spouse exemption can reach, because a death benefit is
     * paid on the member's expression of wish rather than under their will.
     *
     * @param  array<string, mixed>  $state
     */
    private function personPotsNominatedToSpouse(array $state, string $id): int
    {
        $total = 0;
        foreach ($state['pots'][$id] ?? [] as $pot) {
            if ($pot['nominatedToSpouse'] ?? false) {
                $total += $pot['value'];
            }
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
        $spaMonth = [];
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
            // The MONTH is kept as well as the year: State Pension age is reached on a date, not on
            // 1 January, so the year it falls in is a part year for both the pension it starts and
            // the National Insurance it ends ({@see startFraction}).
            $spaDate = StatePensionAge::for($person->dob)->dateReached;
            $spaYear[$person->id] = (int) $spaDate->format('Y');
            $spaMonth[$person->id] = (int) $spaDate->format('n');
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
                    // How much of this pot is already CRYSTALLISED — designated to drawdown, so it
                    // has had its tax-free cash and can never have another quarter ({@see ufplsSplit}).
                    // A starting pot is treated as wholly uncrystallised, which is a v1 limit rather
                    // than a fact: $pclsTakenToDate is an allowance ledger across ALL of the member's
                    // pensions, not a record of what THIS pot crystallised, so the split cannot be
                    // inferred from it without inventing one. Only a PCLS taken WITHIN the projection
                    // is tracked ({@see plannedWithdrawals}). Board card 0080.
                    'crystallised' => 0,
                    // Kept apart, not summed: only the member's own contribution is the household's
                    // money (so only it may be funded from surplus) and only it attracts relief.
                    'contribution' => $pension->ongoingContribution->pence,
                    'employerContribution' => $pension->employerContribution->pence,
                    'reliefMethod' => $pension->reliefMethod,
                    'earliestAccessAge' => $pension->earliestAccessAge,
                    'growthOverrideReal' => $pension->growthAssumptionOverride?->asFraction(),
                    // The member's OWN pot: drawing it flexibly is a trigger event for their MPAA.
                    // An inherited pot is not ({@see inheritEstate}), so the two must be told apart.
                    'inherited' => false,
                    // Whether this pot passes to the surviving spouse or civil partner, which is
                    // what decides the spouse exemption on it at the first death. Held per pot
                    // because the nomination is per pot: a member can leave one to a spouse and
                    // another to a child.
                    'nominatedToSpouse' => $pension->nominatedToSpouse(),
                ];
                $lsaUsed[$pension->ownerId] += $pension->pclsTakenToDate?->pence ?? 0;

                // A planned annuity purchase becomes a pending annuity, bought at its age
                // from this owner's pots (see processAnnuityPurchases).
                if ($pension->annuityPurchase !== null) {
                    $annuities[] = self::annuityState($pension->ownerId, $pension->annuityPurchase, null);
                }
            }
        }

        // The same again for a PURCHASED LIFE ANNUITY: one bought with money that is not pension
        // money, from the named account it hangs off (board card 0060). Its source wrapper is
        // carried so the purchase draws on THAT account and its income is taxed on the interest
        // element only ({@see processAnnuityPurchases}).
        foreach ($household->accounts as $account) {
            if ($account->annuityPurchase !== null) {
                $annuities[] = self::annuityState($account->ownerId, $account->annuityPurchase, $account->type);
            }
        }

        // Each Defined Benefit scheme's escalation facts, keyed by its position in the pension
        // list so dbIncome() and friends can pair a scheme with its own factor. Flattened out of
        // the DTO here because growState() escalates without the Household in hand.
        $dbSchemes = [];
        foreach ($household->pensions as $key => $pension) {
            if ($pension instanceof DbPension) {
                $dbSchemes[$key] = [
                    'ownerId' => $pension->ownerId,
                    'normalRetirementAge' => $pension->normalRetirementAge,
                    'revaluationBasis' => $pension->revaluationBasis,
                    'escalationInPayment' => $pension->escalationInPayment,
                    'fixedRate' => $pension->fixedEscalationRate()->asFraction(),
                ];
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
            'spaMonth' => $spaMonth,
            'spClaimYear' => $spClaimYear,
            'cash' => $cash,
            'gia' => $gia,
            'giaBasis' => $giaBasis,
            'isa' => $isa,
            'pots' => $pots,
            'lsaUsed' => $lsaUsed,
            // Flexible access (a UFPLS or drawdown income, planned or ad-hoc) permanently caps
            // that member's money-purchase contributions at the MPAA; mpContributed counts what
            // has gone in THIS year and is reset each year. {@see contributionHeadroom}.
            'mpaaTriggered' => array_fill_keys(array_keys($lsaUsed), false),
            'mpContributed' => array_fill_keys(array_keys($lsaUsed), 0),
            // The ISA subscription allowance a person has used THIS year, across money paid in
            // and anything moved in from a GIA; reset each year. {@see bedAndIsa}.
            'isaSubscribed' => array_fill_keys(array_keys($lsaUsed), 0),
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
            // The Support for Mortgage Interest charge: a SECOND balance secured on the home,
            // beside the mortgage and never mixed into it. DWP lends what it meets and takes its
            // own charge, so this only ever grows while the household is on Guarantee Credit and
            // is cleared when the home is sold. {@see SupportForMortgageInterest}.
            'smiBalance' => 0,
            // Care fees the year could not fund, secured on the home under a deferred payment
            // agreement and rolling up until the home is sold or the estate is settled
            // ({@see DeferredPaymentAgreement}, board card 0055).
            'deferredCareBalance' => 0,
            // The whole-property (un-scaled) value, grown in lockstep with the share value. A
            // forced sale needs the whole figure to compute CGT on the household's share of the
            // gain (purchase price is whole too); null-share leaves it equal to `property`.
            'propertyWhole' => $household->primaryResidence?->currentValue->pence ?? 0,
            'mortgageRepaid' => false,
            // A forced sale (MortgageMaturityAction::ForcedSale) sells the home in the redemption
            // year, mid-projection, then the household rents. Flips true at that event.
            'homeSold' => false,
            // The former main home this plan has disposed of, driving the Inheritance Tax
            // downsizing addition at the final death. Seeded from the household because the
            // year-0 sell transforms sell BEFORE the projector runs (HousingComparison hands it a
            // household that already holds the proceeds), and overwritten by a forced sale.
            'residenceDisposal' => $household->formerResidenceDisposal,
            'annuities' => $annuities, // planned/active lifetime annuities bought from DC pots
            'careRealTotal' => 0, // accumulated real (today's money) care cost incurred on this path
            // Years so far in the CURRENT local-authority-funded care spell, per person. Drives the
            // 28-day stop on the disability care component ({@see disabilityCareComponentFractions});
            // reset to 0 whenever the person is not in a funded placement.
            'laFundedCareYears' => [],
            'estateSettled' => [], // person ids whose assets have passed to the survivor (once each)
            // Death-in-service lump sums recorded in a member's final working year and paid to the
            // survivor the following year (personId => the payout's facts). Drained when paid.
            'deathBenefit' => [],
            // Running nominal growth factors (1.0 in the base year). salaryFactor is per-person so
            // each person's pay can escalate at their own rate (Person::salaryGrowth override).
            'salaryFactor' => $salaryFactor,
            'salaryGrowthReal' => $salaryGrowthReal, // per-person real override (null = assumption set)
            // One running factor PER Defined Benefit scheme, keyed by its position in the
            // household's pension list, because escalation is a scheme rule and not a household
            // one: a pre-1997 slice with no statutory increase and a CPI-linked slice can sit in
            // the same household. {@see dbSchemes} carries what growState needs to bump each of
            // them without the Household, including which phase (deferred / in payment) it is in.
            'dbFactors' => array_map(static fn (): float => 1.0, $dbSchemes),
            'dbSchemes' => $dbSchemes,
            'spFactor' => 1.0,
            // How the State Pension is uprated, and (for a lock with an end date) the last year
            // the 2.5% floor applies. It also carries the Pension Credit guarantee, which rides
            // the same running factor. {@see StatePensionUprating} for what each choice means.
            'spUprating' => $settings->statePensionUprating,
            'spTripleLockUntilYear' => $settings->tripleLockUntilYear,
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
                // Marked inherited because drawing it is NOT a flexible-access trigger for the heir:
                // beneficiary drawdown is not a member trigger event, so it must not cap the heir's own
                // money-purchase allowance ({@see contributionHeadroom}).
                // Wholly CRYSTALLISED: a beneficiary drawdown fund has already been through the
                // deceased's regime, so no draw from it has a tax-free quarter. Today that agrees
                // with {@see lsaHeadroom} returning nil for an inherited pot, but the two answer
                // different questions (is there a quarter / whose allowance pays for it) and board
                // card 0079 will give an under-75 inheritance headroom again — at which point this
                // field is the only thing left stopping a second quarter on the same money.
                // The heir's own nomination on an inherited pot is unknowable and immaterial: the
                // only death left is the final one, which has no surviving spouse to exempt it.
                $state['pots'][$heir][] = ['value' => $inherited, 'plan' => [], 'crystallised' => $inherited, 'contribution' => 0, 'employerContribution' => 0, 'reliefMethod' => null, 'earliestAccessAge' => 0, 'growthOverrideReal' => null, 'inherited' => true, 'nominatedToSpouse' => false];
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
        // Net cash each person's OWN income produced this year, which is what a surplus is
        // attributed by ({@see attributeSurplus}). Household money nobody generated (Pension
        // Credit, the letting finance-cost reducer) is deliberately absent from it.
        $netPerPerson = array_fill_keys(array_map(static fn ($person): string => $person->id, $household->persons), 0);
        $taxFreeCashNominal = 0;  // pension tax-free cash received this year
        $taxFreeIncomeNominal = 0; // tax-free income streams (e.g. DLA) received this year
        $grossIncomeNominal = 0;
        // Nominal income split by canonical source (YearResult::INCOME_SOURCES).
        $src = array_fill_keys(YearResult::INCOME_SOURCES, 0);

        // The annual allowance / MPAA is a per-TAX-YEAR cap, so the running total of what has
        // been paid into money-purchase pots resets here, before any of this year's
        // contributions (employer, net-pay, or from surplus) are made. The ISA subscription
        // allowance is per tax year too, and is shared between money paid in and anything moved
        // in from a GIA ({@see bedAndIsa}), so its running total resets in the same place.
        $state['mpContributed'] = array_fill_keys(array_keys($state['mpContributed']), 0);
        $state['isaSubscribed'] = array_fill_keys(array_keys($state['mpContributed']), 0);

        // Who had already flexibly accessed a pension when the year opened. Compared at the end of
        // it, this is how the year the MPAA FIRST applies is known — and therefore disclosed to the
        // reader rather than quietly shrinking what their contributions buy ({@see mpaaWarnings}).
        $mpaaAtYearStart = $state['mpaaTriggered'];

        // Any annuity purchases due this year convert part of a DC pot, or of a named non-pension
        // account, into a lifetime income before the year's income is assembled, so the source is
        // reduced and the annuity pays from its purchase year (or from the deferred income age).
        // Selling a GIA holding to buy one realises a gain, carried to the year's CGT charge below.
        $annuityPurchases = $this->processAnnuityPurchases($household, $state, $yearIndex, $calendarYear, $alive, $cumInflation);
        $annuityGains = $annuityPurchases['gains'];

        // Annuitising a PENSION pot crystallises it, so a quarter comes out as a tax-free lump sum
        // and only the balance bought the income above (board card 0065). It is banked here, in the
        // same three places a planned lump sum is banked below, so the money reaches the plan, is
        // attributed to whoever's pension it came out of, and is visible on the cashflow ladder as
        // pension tax-free cash rather than appearing from nowhere.
        foreach ($annuityPurchases['taxFreeCash'] as $annuitantId => $lumpSum) {
            $taxFreeCashNominal += $lumpSum;
            $netPerPerson[$annuitantId] += $lumpSum;
            $src['pension_lump_sum'] += $lumpSum;
        }

        // Board card 0050. Attendance Allowance and the DLA care component stop 28 days into a
        // care placement the local authority funds; the mobility component runs on. Settled HERE,
        // before any income is assembled, because the answer has to reach the income streams
        // below, the Pension Credit severe-disability addition after them and the care charge
        // after that — one determination, three readers, no chance of them disagreeing.
        $disabilityCareFraction = $this->disabilityCareComponentFractions($household, $draws, $state, $alive, $yearIndex);

        // The care component of each person's disability award actually received this year
        // (nominal pence, after any suspension above). Assessable income for the care financial
        // assessment, which the mobility component is not — see the care leg below.
        $careComponentPerPerson = array_fill_keys(array_map(static fn ($person): string => $person->id, $household->persons), 0);

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
            $db = $this->dbIncome($household, $person, $age, $state['dbFactors']);
            $sp = $this->statePensionIncome($household, $person->id, $calendarYear, $state['spClaimYear'][$person->id], $state['spaMonth'][$person->id], $state['spFactor']);
            $otherTaxable = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: true);
            $taxFreeStream = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: false);

            // The care component is paid only for the statutory period of a funded placement, so
            // what it does NOT pay comes off the tax-free income the household banks. The mobility
            // component is untouched: it is inside $taxFreeStream and stays there.
            $careComponent = $this->incomeStreamsNominal($household, $person->id, $age, $cumInflation, taxable: false, only: IncomeStreamType::DisabilityBenefit);
            if (isset($disabilityCareFraction[$person->id])) {
                $paid = (int) round($careComponent * $disabilityCareFraction[$person->id]);
                $taxFreeStream -= $careComponent - $paid;
                $careComponent = $paid;
            }
            $careComponentPerPerson[$person->id] = $careComponent;

            // Planned DC withdrawals due at this age.
            $wd = $this->plannedWithdrawals($state, $person->id, $age);

            // Employer death-in-service cover: recorded in the member's LAST living year, while
            // the salary that sizes it and the lump-sum allowance they have used are both still
            // known. It is PAID next year, when the death is settled — see collectDeathInServiceBenefit().
            $this->recordDeathInServiceBenefit($state, $person, $age, $draws);

            // DB commutation: a tax-free lump sum taken at the member's retirement (the pension
            // itself was reduced for it in dbIncome). Routed as pension tax-free cash, like a PCLS.
            $commutationCash = $this->commutationLumpSumNominal($household, $person->id, $age, $state['dbFactors']);

            $taxablePerPerson[$person->id] += $earnings + $db + $sp + $otherTaxable + $wd['taxable'];
            $taxFreeIncomeNominal += $taxFreeStream;
            $taxFreeCashNominal += $wd['taxFree'] + $commutationCash;
            // The untaxed part of this person's own income: it never reaches the tax pass below,
            // so it is banked here or it drops out of the attribution altogether.
            $netPerPerson[$person->id] += $taxFreeStream + $wd['taxFree'] + $commutationCash;

            $src['salary'] += $earnings;
            $src['defined_benefit'] += $db;
            $src['state_pension'] += $sp;
            $src['other_taxable'] += $otherTaxable;
            $src['tax_free_income'] += $taxFreeStream;
            $src['pension_lump_sum'] += $wd['taxFree'] + $commutationCash;
            $src['pension_drawdown'] += $wd['taxable'];
        }

        // Letting a property does not earn its rent (board card 0030). Where the home is LET, the
        // agent's fee, the empty weeks between tenants, the repairs and safety certificates, and
        // the service charge on the building all come off the gross rent before it is either
        // banked or taxed. Applied here, once, to the owner's taxable income, so the cash the
        // household keeps and the profit HMRC sees can never disagree about the same let.
        $lettingCosts = $this->lettingCostsPerOwner($household, $state, $alive, $ages, $cumInflation, $yearIndex);
        foreach ($lettingCosts as $ownerId => $cost) {
            $taxablePerPerson[$ownerId] -= $cost;
            $src['other_taxable'] -= $cost;
        }

        // Annuity income from any purchased annuities: a guaranteed lifetime income, taxable
        // like other income, paid to the surviving partner at the joint fraction after the
        // annuitant dies. Assigned before the tax pass so it is taxed and counts as assessable
        // income for the Pension Credit test.
        // The exempt capital element of any purchased life annuity, per person: taken OFF the
        // income the tax pass sees and nowhere else, so the money is still spendable cash and
        // still assessable income for both means tests.
        $annuityExempt = [];
        foreach ($this->annuityIncomeNominal($state, $household, $alive, $ages, $cumInflation) as $pid => $annuityAmount) {
            $taxablePerPerson[$pid] += $annuityAmount['income'];
            $src['other_taxable'] += $annuityAmount['income'];
            $annuityExempt[$pid] = $annuityAmount['exempt'];
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
            $netPerPerson[$deathBenefit['heirId']] += $deathBenefit['taxFree'];
            $src['death_in_service'] += $deathBenefit['taxable'] + $deathBenefit['taxFree'];
        }

        // Survivor DB pension: when a DB member dies, a scheme with a survivor's fraction continues
        // that fraction of the pension to the surviving partner for life (the joint-life analogue of
        // the annuity above). Without this the guaranteed DB income silently dropped to £0 on the
        // member's death. Taxed and Pension-Credit-assessable like the member's own DB income.
        foreach ($this->survivorDbIncomeNominal($household, $alive, $state['dbFactors']) as $pid => $dbSurvivor) {
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
        // Kept per person so the shortfall draw can be priced against the WHOLE of this
        // year's income. Savings and dividends stack above non-savings income, so an extra
        // pension withdrawal pushes them across band boundaries and shrinks the Personal
        // Savings Allowance; costing the withdrawal on non-savings income alone understated
        // it (board card 0037). Both are read off OPENING balances here, before any draw,
        // so they are settled for the year and cannot move under the drawing below.
        $savingsPerPerson = [];
        $dividendsPerPerson = [];
        foreach ($household->persons as $person) {
            if (! $alive[$person->id]) {
                continue;
            }
            $cashInterest = (int) round($state['cash'][$person->id] * $cashInterestRate);
            $giaDividends = (int) round($state['gia'][$person->id] * $this->effectiveGiaYield($state, $person->id, $giaYield));
            $investmentIncome = $cashInterest + $giaDividends;
            $savingsPerPerson[$person->id] = $cashInterest;
            $dividendsPerPerson[$person->id] = $giaDividends;

            $taxable = $taxablePerPerson[$person->id];
            $grossIncomeNominal += $taxable + $investmentIncome;
            // Combined pass: non-savings, then cash interest (savings, with the PSA), then
            // GIA dividends (dividend allowance + rates) stacked on top. The hot loop only
            // needs the total, so use the lean integer twin of compute() (same band core).
            $tax = $this->indexedTotalPence(new TaxableIncome(
                // Board card 0060: the capital element of a purchased life annuity is a return of
                // the buyer's own money, so only the interest element is taxed. It comes off HERE
                // and only here, because it is still income the household receives and still
                // income both means tests assess.
                Money::fromPence(max(0, $taxable - ($annuityExempt[$person->id] ?? 0))),
                Money::fromPence($cashInterest),
                Money::fromPence($giaDividends),
            ), $thresholdFactor);
            $ni = $this->niForPerson($household, $person->id, $state, $yearIndex);
            $totalTaxNominal += $tax + $ni;
            $netCashNominal += $taxable + $investmentIncome - $tax - $ni;
            $netPerPerson[$person->id] += $taxable + $investmentIncome - $tax - $ni;
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
        //
        // A receipt that states what the thing SOLD originally cost is a DISPOSAL, not a windfall,
        // so it carries a chargeable gain under the chattels rule (board card 0065). The gain is
        // collected here and charged with the year's other disposals below, so the one annual
        // exempt amount is shared and nothing is taxed twice.
        $chattelGains = [];
        foreach ($household->capitalReceipts as $receipt) {
            if ($receipt->calendarYear !== $calendarYear) {
                continue;
            }
            $amount = (int) round($receipt->amount->pence * $cumInflation);
            $netCashNominal += $amount;
            $grossIncomeNominal += $amount;
            $src['capital_receipt'] += $amount;
            // A receipt is the named owner's money while they live; once they have died it is the
            // household's and is shared like any other unattributable sum.
            if ($alive[$receipt->ownerId] ?? false) {
                $netPerPerson[$receipt->ownerId] += $amount;
            }
            if ($receipt->chattelCost !== null) {
                // The cost is stated in the same today's money as the proceeds, so both are carried
                // to this year's prices together and the REAL gain is what the reader described.
                // The exempt amount is the statutory figure as it stands, frozen exactly like the
                // annual exempt amount it is charged alongside.
                $gain = ChattelsGain::chargeableGain(
                    Money::fromPence($amount),
                    Money::fromPence((int) round($receipt->chattelCost->pence * $cumInflation)),
                    $this->config->cgt->chattelsExemptAmount,
                );
                if ($gain->isPositive()) {
                    $chattelGains[$receipt->ownerId] = ($chattelGains[$receipt->ownerId] ?? 0) + $gain->pence;
                }
            }
        }

        // Buy-to-let finance-cost restriction (since April 2020): a landlord can no longer deduct
        // mortgage interest from rental profit, but gets a basic-rate (20%) tax reducer on the
        // lower of the finance cost and the rental profit. Modelled when the home is LET: the
        // mortgage interest is charged as spend above (a real outflow, no full deduction), and
        // here the household tax falls by 20% × min(interest, rental income). Without this the
        // rent was taxed at the full marginal rate with no relief for the interest — overstating
        // the tax on a let property. v1: household-level (joint-ownership split not separated),
        // capped at the tax due (a reducer cannot create a refund). The base is the rental PROFIT,
        // which since card 0030 is the rent NET of the letting costs deducted above; while profit
        // was approximated by gross rent, a mortgaged let was relieved on rent it never kept.
        // The relievable finance cost is mortgage INTEREST only — capital repaid never attracts
        // relief. An amortising loan knows its own interest for the year (falling as the balance
        // falls); otherwise the whole Mortgage expense line is interest (an interest-only loan).
        // Both are FIXED NOMINAL, exactly as the payment is charged below: interest on a fixed
        // balance at a fixed rate is the same cash every year, so CPI-indexing it overstated the
        // credit (and, once the inflated figure passed the rent, silently read the reducer base
        // off the rent instead of the interest).
        $financeCost = $state['repaymentSchedule'] !== null
            ? (int) round($state['repaymentSchedule']->interestIn($calendarYear)->pence * $state['ownershipShare'])
            : $household->expenseProfile->mortgageCosts()->pence;
        if (($household->primaryResidence?->isLet ?? false) && $financeCost > 0) {
            $rentalProfit = max(0, array_sum($this->rentalIncomePerOwner($household, $alive, $ages, $cumInflation)) - array_sum($lettingCosts));
            $reducerBase = min($financeCost, $rentalProfit);
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
        //
        // The award has to be asked for more than once, because money drawn out of a pension to
        // cover the year's shortfall is itself assessable income (board card 0077) and the
        // shortfall is not known yet. It is asked HERE, off the state as it stands now, so that
        // every re-ask sees the same capital: the forced sale below, the drawdown and the banked
        // surplus all move assets, and an award assessed on a later state would be assessed on a
        // household this one is not. {@see pensionCreditAward} for what the extra income is.
        $stateAtAward = $state;
        $awardAssessedOn = fn (int $extraAssessableAnnual): ?PensionCreditResult => $this->pensionCreditAward(
            $household, $stateAtAward, $alive, $calendarYear, $ages, $taxablePerPerson, $aliveCount,
            $meansTestExcluded, array_keys($disabilityCareFraction), $extraAssessableAnnual,
        );
        $pensionCreditAward = $awardAssessedOn(0);

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
        //
        // Read BEFORE the block so the sale can be told from an already-sold home: a forced sale is
        // a one-year EVENT (board card 0049 warns on it) while $state['homeSold'] stays true for the
        // rest of the plan, so the flag alone would repeat the warning for ever.
        $homeSoldAtYearStart = $state['homeSold'];
        $saleForcedByMaturity = $home?->mortgageRedemptionYear !== null
            && $home->mortgageMaturityAction === MortgageMaturityAction::ForcedSale
            && $calendarYear >= $home->mortgageRedemptionYear;
        // The SAME sale, on the other trigger every standard equity-release contract carries
        // (board card 0056): permanent residential care for the last surviving borrower matures a
        // lifetime mortgage exactly as death does. Run here, before the year's care charge and its
        // financial assessment below, so the resident is charged on the position the sale leaves
        // them in — the proceeds in hand and no home — rather than on a home they no longer have.
        $saleForcedByCare = ! $saleForcedByMaturity
            && $this->equityReleaseRedeemedByCare($household, $state, $draws, $alive, $yearIndex);
        if ($home !== null && ! $state['homeSold'] && ($saleForcedByMaturity || $saleForcedByCare)) {
            // The balance to redeem is the one owed in THIS year, never the one originally
            // entered. The two agree only for an interest-only loan, which is why passing the
            // entered figure survived: a lifetime mortgage has rolled up by now (so the sale was
            // freeing equity the household no longer had) and a repayment mortgage has amortised
            // down (so it was freeing less than it really keeps). Both shapes are live.
            //
            // `mortgageOutstanding` is the household's SHARE of the balance and HousingProceeds
            // takes whole-property figures and applies the share itself, so scale back up. Derived
            // rather than tracked as a second state key: the balance has ONE definition, it is read
            // once here rather than compounded (unlike `propertyWhole`, whose drift would accumulate
            // over a whole projection), and a mirrored key is a field to forget.
            $share = $state['ownershipShare'];
            $owedWhole = $share > 0
                ? (int) round($state['mortgageOutstanding'] / $share)
                : $state['mortgageOutstanding'];

            $proceeds = HousingProceeds::compute(
                Money::fromPence($state['propertyWhole']),
                Money::fromPence($owedWhole),
                $settings->sellingCosts,
                $home->cgtHistory,
                $home->ownershipShare,
                $this->config,
            );

            // The net proceeds become investable liquid wealth, split equally between the living
            // OWNERS' GIAs (drawable now, invested per the run's assumptions and drawn per the
            // strategy). Cost basis = proceeds, so no latent gain is taxed on a later disposal.
            // Once in the GIA the freed equity is assessable capital for Pension Credit (it is no
            // longer the exempt main residence), so a forced sale can erode the award / cross the
            // £16k cliff. It is split rather than banked to the first living person (board card
            // 0040) because the care means test assesses the individual: crediting one of them
            // with the whole home sent the other into care owning nothing.
            // A Support for Mortgage Interest charge is secured on this home, so the sale redeems
            // it out of the proceeds before anything is banked — that is what "repaid on sale"
            // means, and it is why the charge does not follow the household into a rented flat.
            // Any shortfall against the proceeds is written off (DWP recovers only what the
            // security bears), which is what the floor here does. It is redeemed SEPARATELY from
            // the mortgage rather than added to the redeemed balance, because HousingProceeds
            // decomposes the sale and a second, differently-owed debt inside its `mortgage` line
            // would report a mortgage the household does not have.
            // Record the disposal for the Inheritance Tax downsizing addition BEFORE the charges
            // are cleared: the value that counts is the household's own interest in the home at
            // the moment it was sold — its share of the price less everything secured on it, the
            // same net basis the estate values a home on at death. A later disposal REPLACES an
            // earlier one (a year-0 sale followed by a forced sale on the home bought with the
            // proceeds): the statute allows one addition, computed from a single qualifying
            // disposal, and the most recent one is the one the estate's own history ends on.
            // A deferred care payment is secured on the same home and falls due on the same sale,
            // so it is redeemed beside the SMI charge and on the same terms (board card 0055).
            $securedCharges = $state['smiBalance'] + $state['deferredCareBalance'];
            $state['residenceDisposal'] = new ResidenceDisposal(
                Money::fromPence(max(0, $proceeds->salePrice->pence - $proceeds->outstandingMortgage->pence - $securedCharges)),
                $calendarYear,
            );

            $netAfterCharge = max(0, $proceeds->netProceeds->pence - $securedCharges);
            $state['smiBalance'] = 0;
            $state['deferredCareBalance'] = 0;

            foreach (PenceSplit::evenly($netAfterCharge, $this->livingIds($household, $alive)) as $ownerId => $share) {
                $state['gia'][$ownerId] += $share;
                $state['giaBasis'][$ownerId] += $share;
            }

            // Clear the home and its debt; flip onto a renting footing from here.
            $state['property'] = 0;
            $state['propertyWhole'] = 0;
            $state['mortgageOutstanding'] = 0;
            $state['mortgageRepaid'] = true; // stops the ongoing mortgage payment (dropped just below)
            $state['homeSold'] = true;
        }

        // The "Mortgage" expense line comes out of the CPI-and-survivor-multiplied buckets
        // ALWAYS, and is re-added below as a fixed nominal cost when it is still owed. A mortgage
        // payment is neither indexed nor survivor-scaled: interest on a fixed balance at a fixed
        // rate is the same cash every year (so CPI-indexing it held its real cost flat and
        // removed the inflation hedge on a nominal debt, penalising every borrowing route), and
        // a lender does not reduce the payment because a borrower died. This is the treatment the
        // amortisation schedule already had; the other three product shapes (interest-only, RIO,
        // buy-to-let, a serviced lifetime mortgage) never got it.
        //
        // Dropping it here also stops it being charged at all once it is no longer owed: the
        // mortgage was redeemed from capital (unlike service charge / ground rent, which continue
        // while the home is owned), or the home was sold, or the household carries a
        // capital-and-interest schedule that owns the payment itself. Sell variants already
        // removed the line via withoutPropertyCosts.
        $mortgagePay = $household->expenseProfile->mortgageCosts()->pence;
        $targetPence = max(0, $targetPence - $mortgagePay);
        $essentialPence = max(0, $essentialPence - $mortgagePay);

        // After a forced sale the home is gone, so its property costs (service charge / ground
        // rent — the while_owning_home bucket) stop too, alongside the running costs below. The
        // year-0 sell variants drop these via withoutPropertyCosts; here they drop from the sale year.
        // What the charge BOUGHT in utilities is not dropped with it: the household still heats and
        // plumbs whatever it lives in next, so that part stays as ordinary spend (the same rule the
        // year-0 sell variants apply in withoutPropertyCosts).
        if ($state['homeSold']) {
            $propCosts = $household->expenseProfile->propertyCosts()
                ->minus($household->expenseProfile->propertyCostsUtilities())->pence;
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

        // The year's one-off CAPITAL lumps, kept as a labelled list rather than one anonymous
        // total: a documented one-off cost (the unfunded part of a home purchase is the worked
        // example) plus a mortgage redeemed from capital. They join the spend target like any
        // other outflow, but they are told apart from the recurring budget when the year is
        // judged below — a lump the plan cannot fund is a failure of that lump, not of the
        // household's ordinary spending.
        $oneOffs = $this->oneOffCostsNominal($household, $ages, $cumInflation, $state['homeSold']);
        if ($repayOneOff > 0) {
            $oneOffs[] = ['label' => 'Mortgage redemption', 'amount' => $repayOneOff];
        }
        $oneOffTotalNominal = array_sum(array_column($oneOffs, 'amount'));

        // The spending guardrail (board card 0063). Everything above scores the year against a
        // FIXED real target, which no real household spends into insolvency: measured against the
        // essential spend still to be funded, a plan that is short cuts back. The test is re-run
        // here every year off THIS year's own opening wealth, which is what puts the spend back
        // when the plan recovers — there is no latch to get stuck.
        //
        // Usable wealth here is liquid + pension GROSS, so the home the household lives in is never
        // counted as spendable. It is deliberately not the reported spendable figure, which nets the
        // tax on the pot ({@see YearResult::usableWealth()}, board card 0076): this ratio asks what
        // assets the plan has to meet its spending with, and a pot meets it in taxed instalments
        // over decades rather than in one encashment. Netting it here would also make the reader's
        // cut-back rule move a projection, which the card that netted the report did not decide. It
        // is read at the year's OPEN: the drawdown that funds this year has not run yet, and a ratio
        // taken after it would describe a household that had already spent the money the rule is
        // deciding about.
        $guardrailCutNominal = 0;
        $guardrailNoFlexibility = false;
        $guardrail = $household->expenseProfile->spendingGuardrail;
        if ($guardrail !== null) {
            $essentialThisYearNominal = (int) round($essentialPence * $state['spendFactor'] * $survivor);
            $usable = $this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']) + $this->totalPots($state);
            $bites = $guardrail->bites(
                Money::fromPence($usable),
                Money::fromPence($essentialThisYearNominal * $this->yearsRemaining($household, $draws, $alive, $ages)),
            );
            if ($bites) {
                $discretionaryPence = max(0, $targetPence - $essentialPence);
                // A household whose whole spend is its essential floor has nothing to cut, so the
                // guardrail cannot help it. That is the finding, not a no-op: it is raised as a
                // warning rather than left as a silent zero.
                $guardrailNoFlexibility = $discretionaryPence === 0;
                $targetBeforeCut = $targetPence;
                $targetPence -= $guardrail->cutFrom(Money::fromPence($discretionaryPence))->pence;
                // Both sides of the cut are taken through the SAME nominal expression the spend
                // below is, so what the year reports having trimmed is exactly what it trimmed.
                $guardrailCutNominal = (int) round($targetBeforeCut * $state['spendFactor'] * $survivor)
                    - (int) round($targetPence * $state['spendFactor'] * $survivor);
            }
        }

        $spendNominal = (int) round($targetPence * $state['spendFactor'] * $survivor) + $oneOffTotalNominal;
        $essentialNominal = (int) round($essentialPence * $state['spendFactor'] * $survivor);

        // The mortgage payment is added back HERE, after the CPI and survivor multiplies, because
        // it is neither: it is FIXED NOMINAL (a £1,318.54 instalment is £1,318.54 in year 16,
        // falling in real terms), and the survivor owes the lender exactly what the couple owed —
        // a death does not shrink it the way it shrinks the food bill. It is an essential cost
        // (the alternative is repossession) and it stops dead once the debt does.
        //
        // A capital-and-interest mortgage is charged from its own amortisation schedule, which
        // steps when the deal rate reverts and returns zero at the end of the term; every other
        // product shape (interest-only, RIO, buy-to-let, a serviced lifetime mortgage) is charged
        // the "Mortgage" expense line taken out of the buckets above.
        $mortgagePaymentNominal = 0;
        if (! $state['mortgageRepaid'] && ! $state['homeSold']) {
            $mortgagePaymentNominal = $state['repaymentSchedule'] !== null
                ? (int) round($state['repaymentSchedule']->paymentIn($calendarYear)->pence * $state['ownershipShare'])
                : $mortgagePay;
            $spendNominal += $mortgagePaymentNominal;
            $essentialNominal += $mortgagePaymentNominal;
        }

        // ================================================================================
        // Board card 0077. Everything from here to the end of the drawdown is ONE PASS at a
        // fixed point, because the award and the draw each decide the other: the award is part
        // of the income that covers the spending, so it sets the shortfall; the shortfall sets
        // how much has to come out of a pension; and taxable pension money is assessable income
        // for the means test, so it sets the award. Solved in one direction only, which is the
        // order that does not need solving twice and the order this engine used to run, a
        // household could draw thousands out of a pot and keep a credit that in life would have
        // been taken away pound for pound.
        //
        // Each pass runs against a RESTORED state, so a pass is never charged twice for the
        // Support for Mortgage Interest it met, the care it was assessed for or the assets it
        // drew. It settles when the award the pass USED is the award that pass's own draw
        // implies, which is the reconciliation the reader is shown.
        //
        // The overwhelming majority of years settle on the first pass and are byte-identical to
        // the pre-card engine: a household with no award has nothing to claw back, and a
        // household that draws nothing taxable out of a pension has changed nothing the means
        // test can see.
        //
        // The DRAW ORDER, though, is settled once and holds for every pass: it asks whether the
        // household is on Guarantee Credit, and it is, before it draws anything. Letting it read
        // the pass's own clawed-back award instead put the iteration on the wrong answer of two
        // self-consistent ones: a pass that had lost the whole award stopped protecting a credit
        // it no longer had, filled the free tax bands out of the pension, and left the capital
        // that would have kept the award untouched. Pinned by
        // PathProjectorTest::test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact.
        $onGuaranteeCredit = ($pensionCreditAward?->guaranteeCreditWeekly->pence ?? 0) > 0;
        $passState = $state;
        $passSpendNominal = $spendNominal;
        $passEssentialNominal = $essentialNominal;
        $passNetCashNominal = $netCashNominal;
        $passGrossIncomeNominal = $grossIncomeNominal;
        $passTotalTaxNominal = $totalTaxNominal;
        $passSrc = $src;
        // The taxable pension draw this pass's award is assessed on, and the pass before it:
        // the two points the secant step below extrapolates through.
        $assessedDrawNominal = 0;
        $previousAssessedDraw = null;
        $previousGap = null;
        for ($pass = 0; ; $pass++) {
            $benefitNominal = $pensionCreditAward === null
                ? 0
                : $pensionCreditAward->guaranteeCreditWeekly->pence * $this->config->statePension->weeksPerYear;
            $netCashNominal += $benefitNominal;
            $grossIncomeNominal += $benefitNominal;
            $src['means_tested_benefit'] += $benefitNominal;

            // Support for Mortgage Interest: a household on Guarantee Credit qualifies with no waiting
            // period, and DWP meets the interest on eligible mortgage capital (up to its cap, at its
            // own standard rate) plus — for a pension-age claimant — the service charge and ground
            // rent. It is a LOAN, so it is not credited as income: the bill simply stops arriving, and
            // what was met is added to a charge on the home that is repaid on sale or at death. Both
            // sides come off ONE figure, so what the household is spared and what it owes cannot
            // disagree. {@see SupportForMortgageInterest}, board card 0045.
            $smiMetNominal = $this->supportForMortgageInterestNominal(
                $household, $state, $benefitNominal, $mortgagePaymentNominal, $propertyGrowth, $survivor, $yearIndex,
            );
            if ($smiMetNominal > 0) {
                $spendNominal = max(0, $spendNominal - $smiMetNominal);
                $essentialNominal = max(0, $essentialNominal - $smiMetNominal);
                $state['smiBalance'] += $smiMetNominal;
            }

            // Rent (the "sell and rent" leg) is an essential cost with its own inflation. It applies
            // once the household no longer owns a home: always for a year-0 rent variant (no
            // primaryResidence), or from the sale year for a forced sale. An owner still in the home
            // pays no rent (even where a post-sale rent figure is set for the forced-sale years).
            $ownsHome = $home !== null && ! $state['homeSold'];
            $rentChargedNominal = 0;
            $housingBenefitNominal = 0;
            if ($settings->annualRent !== null && ! $ownsHome) {
                $rentChargedNominal = (int) round($settings->annualRent->pence * $state['rentFactor']);

                // Housing Benefit meets some or all of that rent for a pension-age renter whose income
                // and capital qualify (board card 0048). It comes off the rent rather than being
                // credited as income, the way Council Tax Reduction comes off the council tax: it is
                // paid towards one bill and cannot be spent on anything else, so banking it as income
                // would let the household eat it.
                //
                // $rentChargedNominal stays the GROSS rent the landlord asks for, because that is what
                // the deposit and the referencing warnings below are sized against: a letting agent's
                // affordability test is on the rent, not on what the tenant is left paying.
                $housingBenefitNominal = $this->housingBenefitNominal($household, $state, $pensionCreditAward, $rentChargedNominal);
                $rentPaidNominal = max(0, $rentChargedNominal - $housingBenefitNominal);

                $spendNominal += $rentPaidNominal;
                $essentialNominal += $rentPaidNominal;
            }

            // Property running costs (maintenance, insurance) for owners are essential too — the
            // counterpart to a renter's rent. They stop once the home is sold.
            if ($household->primaryResidence?->runningCosts !== null && ! $state['homeSold']) {
                // Only the household's share of the running costs (it owns a share of the home, entered whole).
                $runningNominal = (int) round($household->primaryResidence->runningCosts->pence * $state['spendFactor'] * $state['ownershipShare']);
                $spendNominal += $runningNominal;
                $essentialNominal += $runningNominal;
            }

            // Council tax, held apart from the running costs above because it is the one that
            // SHRINKS — see councilTaxNominal for the three reliefs and the order they apply in.
            $councilTaxNominal = $this->councilTaxNominal($household, $state, $pensionCreditAward, $aliveCount);
            $spendNominal += $councilTaxNominal;
            $essentialNominal += $councilTaxNominal;

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
                    // Plus the CARE component of any disability award still in payment (board card
                    // 0050). A financial assessment takes Attendance Allowance and the DLA care
                    // component into account like any other undisregarded income; only the mobility
                    // component is left out, and it is left out by never being added here.
                    assessableAnnualIncome: Money::fromPence($taxablePerPerson[$person->id] + $pensionCreditPerPerson + $careComponentPerPerson[$person->id]),
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
            // A GIA sold to buy a purchased life annuity earlier this year is the same kind of event,
            // so it seeds the same way and shares the same annual exempt amount (board card 0060).
            // A chattel sold this year (board card 0065) is the same kind of event and seeds the same
            // way, so the sale of a painting and the sale of a share holding share one exempt amount.
            $seedGains = $annuityGains;
            foreach ($chattelGains as $pid => $gain) {
                $seedGains[$pid] = ($seedGains[$pid] ?? 0) + $gain;
            }
            if ($yearIndex === 0 && $household->realisedGainsAtStart !== []) {
                foreach ($household->realisedGainsAtStart as $pid => $gain) {
                    $seedGains[$pid] = ($seedGains[$pid] ?? 0) + $gain->pence;
                }
            }
            if ($seedGains !== []) {
                $seedCgt = $this->capitalGainsTax($seedGains, $taxablePerPerson, $alive);
                if ($seedCgt > 0) {
                    $totalTaxNominal += $seedCgt;
                    $netCashNominal -= $seedCgt;
                }
            }

            // Fund any shortfall from assets per the drawdown strategy.
            $shortfall = $spendNominal - $netCashNominal;
            $fundedNominal = 0;
            $gainsThisYear = $seedGains;
            $taxablePensionDrawn = 0;
            if ($shortfall > 0) {
                $funded = $this->fundShortfall($household, $settings, $state, $alive, $ages, $taxablePerPerson, $savingsPerPerson, $dividendsPerPerson, $shortfall, $thresholdFactor, $onGuaranteeCredit, $seedGains);
                $fundedNominal = $funded['funded'];
                $totalTaxNominal += $funded['extraTax'];
                // The tax-free quarter of a UFPLS-style ad-hoc draw goes on the tax-free cash line and
                // only the balance on the drawdown line, which the ladder labels as taxable pension
                // income (board card 0074). The two are one gross split in two, never two sums, so the
                // year still reconciles to the money that left the pots.
                $src['pension_lump_sum'] += $funded['fromPensionTaxFree'];
                $src['pension_drawdown'] += $funded['fromPension'] - $funded['fromPensionTaxFree'];
                $src['asset_drawdown'] += $funded['fromAssets'];
                // What the means test can see of that draw: the TAXABLE part alone. The tax-free
                // quarter is capital in the claimant's hands, not income, so it is left out here
                // and the capital it becomes is assessed the way any other capital is — by the
                // tariff, at the open of the year after it was drawn.
                $taxablePensionDrawn = $funded['fromPension'] - $funded['fromPensionTaxFree'];
                // The disposals that funded the year already counted against each person's CGT
                // annual exempt amount (they include $seedGains, shared once), so bed-and-ISA below
                // reads them rather than re-claiming an allowance that is already spent.
                $gainsThisYear = $funded['realisedGain'];
            } elseif ($shortfall < 0) {
                // Surplus first funds any planned contributions to long-term assets
                // (DC pension top-ups, regular account savings); what remains is saved as cash, in the
                // name of whoever's income produced it (board card 0040). Banking it all to the first
                // living person made the care means test, which assesses the individual, depend on the
                // order the two people were typed in.
                $surplus = -$shortfall;
                $surplus -= $this->applyContributions($household, $state, $alive, $ages, $state['spendFactor'], $surplus);
                if ($surplus > 0) {
                    foreach ($this->attributeSurplus($surplus, $netPerPerson, $alive) as $ownerId => $share) {
                        $state['cash'][$ownerId] += $share;
                    }
                }
            }

            // Has the pass settled? It has when re-assessing the award on the income this pass
            // actually produced leaves the award where the pass had it. A household with no award
            // is settled by definition: no further income can claw back a credit of nil.
            $reassessed = $benefitNominal === 0 ? null : $awardAssessedOn($taxablePensionDrawn);
            if ($benefitNominal === 0
                || $taxablePensionDrawn === $assessedDrawNominal
                || ($reassessed?->guaranteeCreditWeekly->pence ?? 0) === $pensionCreditAward?->guaranteeCreditWeekly->pence) {
                break;
            }
            if ($pass >= self::MAX_PENSION_CREDIT_PASSES) {
                // Not settled inside the budget. The last pass stands, with its award assessed on
                // the pass before it — the same one-directional answer the engine gave before this
                // card, for the one shape of household the iteration cannot pin down. It is not
                // reachable by the arithmetic below (the step lands exactly on a straight line, and
                // the taper is one), so it is a backstop rather than a case.
                break;
            }

            // Where to assess the next pass. Each pound of assessable income takes a pound of the
            // award, and each pound of award lost has to be drawn out of the pot instead, so
            // stepping straight to what this pass drew converges by only a quarter of the gap at a
            // time (three quarters of a draw is taxable) and would need scores of passes to land on
            // the penny. The gap between what a pass assessed and what it drew is a straight line
            // in that assessed figure, so the secant through the last two passes lands on its root
            // at once; the first pass has no second point and takes the plain step.
            $gap = $taxablePensionDrawn - $assessedDrawNominal;
            $next = $previousGap !== null && $previousGap !== $gap
                ? (int) round($assessedDrawNominal - $gap * ($assessedDrawNominal - $previousAssessedDraw) / ($gap - $previousGap))
                : $taxablePensionDrawn;
            $previousAssessedDraw = $assessedDrawNominal;
            $previousGap = $gap;
            $assessedDrawNominal = max(0, $next);
            $pensionCreditAward = $awardAssessedOn($assessedDrawNominal);

            $state = $passState;
            $spendNominal = $passSpendNominal;
            $essentialNominal = $passEssentialNominal;
            $netCashNominal = $passNetCashNominal;
            $grossIncomeNominal = $passGrossIncomeNominal;
            $totalTaxNominal = $passTotalTaxNominal;
            $src = $passSrc;
        }

        // The two contingency disclosures the award itself cannot carry (board card 0046), built
        // on the SETTLED award and on the state the means test read — the year's assets are drawn
        // down and its surplus banked above, and a warning computed off that later state would
        // describe a different household.
        $benefitWarnings = $this->benefitContingencyWarnings($household, $stateAtAward, $pensionCreditAward, $benefitNominal > 0, $alive, $calendarYear);

        // Board card 0073. The annual allowance is settled HERE and nowhere else: after every
        // contribution route (employer, net-pay, surplus) and after every withdrawal that can set
        // the MPAA trigger, so the allowance measured against is the one the member actually had
        // and going over it is a BILL rather than a wall. Contributions themselves are no longer
        // refused ({@see payIntoPot}), so the money is in the pot and only the charge is missing.
        //
        // The charge is paid out of the cash the member holds, exactly as any other tax bill would
        // be; what their cash cannot meet comes off the year's net income instead, so it lands in
        // unmet spend below rather than being quietly forgiven.
        $aaCharges = $this->annualAllowanceCharges($state, $alive, $taxablePerPerson, $savingsPerPerson, $dividendsPerPerson, $thresholdFactor);
        foreach ($aaCharges as $chargedId => $charge) {
            $totalTaxNominal += $charge;
            $fromCash = min($charge, max(0, $state['cash'][$chargedId] ?? 0));
            $state['cash'][$chargedId] -= $fromCash;
            $netCashNominal -= $charge - $fromCash;
        }

        // Use what is left of the ISA allowance on money the household ALREADY holds in a taxable
        // account. Runs last, so it sees the year's contributions and disposals and cannot claim
        // an allowance either has spent; runs every year, including a drawdown year, because a
        // sell-and-invest plan has a shortfall in almost all of them and that is exactly the plan
        // this shelters. {@see bedAndIsa}.
        $isaShelteredNominal = $settings->useIsaAllowance
            ? $this->bedAndIsa($household, $state, $alive, $gainsThisYear)
            : 0;

        $metSpend = min($spendNominal, $netCashNominal + $fundedNominal);
        $unmetNominal = max(0, $spendNominal - $metSpend);

        // Board card 0055. The funding waterfall above draws on cash, investments, ISAs and
        // pensions and NEVER on the home, so a self-funding homeowner in care ran an unfundable
        // care charge every year: the year failed its essentials and the plan was penalised for
        // keeping a property that in life would simply have carried the debt. What an authority
        // actually offers is a DEFERRED PAYMENT — it pays the fees and secures what it has paid
        // on the home. So the part of the charge the year could not meet becomes a debt rather
        // than an unmet essential, capped at the equity the security can still bear. Only where
        // the home is ASSESSABLE: a home the means test disregards is one the authority has no
        // charge to take, and a resident whose home is disregarded is funded anyway.
        // Not credited as income and never added to $src — it is a loan, exactly like the
        // Support for Mortgage Interest charge beside it, and the SAME figure that meets the
        // spending is what is owed, so the two can never disagree.
        if ($careChargedNominal > 0 && $unmetNominal > 0 && $this->careHomeAssessable($household, $state, $aliveCount)) {
            $deferred = DeferredPaymentAgreement::deferrableThisYear(
                Money::fromPence($unmetNominal),
                Money::fromPence($careChargedNominal),
                Money::fromPence($this->careHomeEquity($household, $state)),
            )->pence;

            $metSpend += $deferred;
            $unmetNominal -= $deferred;
            $state['deferredCareBalance'] += $deferred;
        }

        $essentialsMet = $metSpend >= $essentialNominal;

        // Recurring spend is funded BEFORE a one-off capital lump — a household eats and heats
        // itself before it completes a purchase — which is the same funding order $essentialsMet
        // above already assumes. So charge the year's shortfall against its one-offs first. What
        // remains is the recurring budget that genuinely went short, and it is that (not the
        // lump) which {@see YearResult::fullSpendMet()} judges: a year-0 purchase gap is the same
        // constant on every sampled path, so folding it in reported the full-spend probability as
        // exactly 0.000 for a plan whose ordinary spending was met in every single year.
        $unmetOneOffNominal = min($unmetNominal, $oneOffTotalNominal);

        // Real (today's money) figures.
        $realFactor = 1.0 / $cumInflation;
        $r = fn (int $nominal): Money => Money::fromPence((int) round($nominal * $realFactor));

        $liquid = $this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']);
        $pension = $this->totalPots($state);

        // What the pots left standing would cost in tax to spend, so the SPENDABLE wealth figure
        // stops counting a pension pot as if it were cash ({@see YearResult::usableWealth()},
        // board card 0076). Read off the same pots $pension was summed from, after every draw this
        // year, so the two cannot describe different money.
        $pensionDraw = $this->pensionTaxIfDrawn($state, $taxablePerPerson, $savingsPerPerson, $dividendsPerPerson, $thresholdFactor);

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
            unmetOneOffSpend: $m($unmetOneOffNominal),
            essentialsMet: $essentialsMet,
            liquidWealth: $m($liquid),
            pensionWealth: $m($pension),
            propertyWealth: $m($state['property']),
            incomeBySource: array_map($m, $src),
            warnings: [
                ...$this->mpaaWarnings($state, $mpaaAtYearStart),
                ...$this->allowanceChargeWarnings($state, $aaCharges, $m),
                ...$this->unfundedOneOffWarnings($oneOffs, $unmetOneOffNominal, $m),
                ...$this->tenancyUpFrontWarnings($oneOffs, $rentChargedNominal, $m),
                ...$this->rentReferencingWarnings($rentChargedNominal, $grossIncomeNominal, $m),
                ...$this->deprivationWarnings(
                    $oneOffs,
                    $src,
                    ! $homeSoldAtYearStart && $state['homeSold'],
                    (int) round($this->config->benefits->housingSupportUpperCapitalLimit->pence * $cumInflation),
                    $m,
                ),
                ...$benefitWarnings,
                ...($guardrailNoFlexibility ? [new Warning(
                    WarningCode::GUARDRAIL_NO_FLEXIBILITY,
                    'Your spending guardrail was triggered this year, but there is no discretionary '
                    .'spending left to cut: the whole of your spend is the essential floor. A '
                    .'household with nothing to trim gets no protection from a guardrail, so this '
                    .'plan carries the full risk of the target it is scored against.',
                )] : []),
            ],
            mortgageBalance: $m($state['mortgageOutstanding']),
            nominal: $nominal,
            isaSheltered: $m($isaShelteredNominal),
            smiBalance: $m($state['smiBalance']),
            deferredCareBalance: $m($state['deferredCareBalance']),
            councilTax: $m($councilTaxNominal),
            housingBenefit: $m($housingBenefitNominal),
            guardrailReduction: $m($guardrailCutNominal),
            pensionTaxableIfDrawn: $m($pensionDraw['taxable']),
            pensionTaxIfDrawn: $m($pensionDraw['tax']),
        );

        return $build($r, $build(Money::fromPence(...), null));
    }

    /**
     * How many more years this projection has to fund, counting the current one: the longest
     * remaining life among the LIVING members, from the death ages this path was handed. It is
     * the denominator of the spending guardrail's funded ratio — what the plan still owes — and
     * it reads the same death ages the loop ends on, so the two can never describe different
     * lifespans. Never below 1: the year being projected always has to be funded.
     *
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     */
    private function yearsRemaining(Household $household, PathDraws $draws, array $alive, array $ages): int
    {
        $remaining = 1;
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            $remaining = max($remaining, $draws->deathAge($person->id) - ($ages[$person->id] ?? 0) + 1);
        }

        return $remaining;
    }

    /**
     * This year's Pension Credit Guarantee Credit assessment, or null when the qualifying-age
     * gate blocks it outright. The caller annualises the weekly award; the whole result is
     * returned rather than that one figure because the assessment carries what the year has to
     * DISCLOSE as well as what it pays ({@see benefitContingencyWarnings} — a near miss reads the
     * income against the guarantee, and recomputing that beside the award would be a second
     * definition of the same test).
     *
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
     * @param  array<string, int>  $ages  this year's age per person, which decides whether a
     *                                    disability benefit with a start age is yet in payment
     * @param  array<string, int>  $taxablePerPerson
     * @param  array<string, int>  $excludedFromAssessable  taxable receipts that are CAPITAL for the
     *                                                      means test, not income (a death-in-service
     *                                                      lump sum): taxed as income, assessed as capital
     * @param  list<string>  $inFundedCarePlacement  person ids in a local-authority-funded care
     *                                               placement this year. The severe-disability
     *                                               addition rides the disability CARE component,
     *                                               which stops there ({@see DisabilityBenefitInCare}),
     *                                               so they no longer qualify for it, nor does a
     *                                               partner qualify as their carer. The whole year is
     *                                               treated as stopped, although the first one keeps
     *                                               28 days of the benefit itself: the annual grid
     *                                               cannot pay a part-year addition, and dropping it
     *                                               is the adverse of the two roundings.
     * @param  int  $extraAssessableAnnual  taxable pension money the year is expected to draw to
     *                                      cover its shortfall, which is assessable income like
     *                                      any other pension income (board card 0077). It is not
     *                                      in $taxablePerPerson, which is the income known BEFORE
     *                                      the shortfall is funded, and the caller settles the two
     *                                      against each other. Household-level: Guarantee Credit
     *                                      is assessed on the household's income, so which member
     *                                      drew it does not change the award.
     */
    private function pensionCreditAward(Household $household, array $state, array $alive, int $calendarYear, array $ages, array $taxablePerPerson, int $aliveCount, array $excludedFromAssessable = [], array $inFundedCarePlacement = [], int $extraAssessableAnnual = 0): ?PensionCreditResult
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
                return null;
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
        // A benefit with a start age counts only once the person has reached it, so a claim made
        // later in life (Attendance Allowance as health declines) raises the guarantee from that
        // year and not before.
        // The award must be a QUALIFYING one: the middle or highest rate DLA care component,
        // Attendance Allowance at either rate, or the PIP daily living component. A mobility-only
        // or lowest-rate-care award pays real money and confers neither addition, which the bare
        // flag could not say ({@see Person::qualifiesForSevereDisabilityAdditionAt}, board card 0051).
        $qualifies = fn (string $personId, Person $person): bool => $person->qualifiesForSevereDisabilityAdditionAt($ages[$personId] ?? 0)
            && ! in_array($personId, $inFundedCarePlacement, true);

        $disabledCount = 0;
        foreach ($living as $personId => $person) {
            if ($qualifies($personId, $person)) {
                $disabledCount++;
            }
        }
        $severeDisability = $aliveCount === 1 ? $disabledCount === 1 : $disabledCount >= 2;

        // Carer additions: EACH living member with (underlying) entitlement to Carer's Allowance,
        // i.e. caring for a living partner who receives a qualifying disability benefit, carries
        // an addition of their own. A couple where each cares for the other therefore gets two,
        // which is why this counts rather than stopping at the first carer it finds. Carer's
        // Allowance rests on the same qualifying-benefit list as the severe-disability addition,
        // so both read the one predicate above. It does not remove the cared-for partner's own
        // severe-disability addition (only PAID Carer's Allowance would).
        $carers = 0;
        foreach ($living as $carerId => $person) {
            if (! $person->caresForPartner) {
                continue;
            }
            foreach ($living as $partnerId => $partner) {
                if ($partnerId !== $carerId && $qualifies($partnerId, $partner)) {
                    $carers++;
                    break;
                }
            }
        }

        $assessableIncomeWeekly = Money::fromPence((int) round(($assessableAnnual + $extraAssessableAnnual) / $weeksPerYear));

        $applicableBase = $this->pensionCredit->applicableAmountWeekly($aliveCount === 2, $severeDisability, $carers);
        $applicableWeekly = Money::fromPence((int) round($applicableBase->pence * $state['spFactor']));

        return $this->pensionCredit->award($applicableWeekly, $assessableIncomeWeekly, $this->meansTestAssessableCapital($household, $state));
    }

    /**
     * This year's council tax, as annual nominal pence, after everything that reduces it:
     * the disabled band reduction, the single-person discount and Council Tax Reduction, in the
     * order {@see CouncilTax} applies them.
     *
     * Zero unless the household entered a council tax figure of its own and still owns the home
     * (a bill still bundled inside {@see Property::$runningCosts} is charged there, in full, and
     * the result note says so). It rides CPI like every other spend line, but is NOT scaled by
     * the ownership share: council tax is charged to whoever lives in the dwelling, not to its
     * owners in proportion.
     *
     * The pension-age Council Tax Reduction is applied only where the Pension Credit means test
     * itself ran ($award is non-null — every living member has reached State Pension age). A
     * younger household falls under its council's OWN working-age scheme, which is not prescribed
     * and differs in every district, so awarding nothing there is the honest and adverse answer.
     *
     * @param  array<string, mixed>  $state
     * @param  ?PensionCreditResult  $award  null when the qualifying-age gate blocked the award,
     *                                       which is also what blocks the pension-age reduction
     */
    private function councilTaxNominal(Household $household, array $state, ?PensionCreditResult $award, int $aliveCount): int
    {
        $home = $household->primaryResidence;
        if ($home?->annualCouncilTax === null || $state['homeSold']) {
            return 0;
        }

        $liability = CouncilTax::liabilityAnnual($home->annualCouncilTax, $home->disabledBandReduction, $aliveCount === 1);
        $nominal = Money::fromPence((int) round($liability->pence * $state['spendFactor']));

        if ($award === null) {
            return $nominal->pence;
        }

        // The reduction is computed against the NOMINAL liability, because the income and the
        // guarantee it is tapered against are this year's nominal figures too.
        return $nominal->minus(CouncilTax::reductionAnnual(
            $nominal,
            $award,
            $this->meansTestAssessableCapital($household, $state),
            $this->config->benefits->housingSupportUpperCapitalLimit,
            $this->config->statePension->weeksPerYear,
        ))->minZero()->pence;
    }

    /**
     * What Housing Benefit meets of this year's rent, as annual nominal pence. It comes off the
     * rent the household pays; nothing is credited as income.
     *
     * Awarded only where the Pension Credit means test itself ran ($award is non-null — every
     * living member has reached State Pension age), for the reason {@see councilTaxNominal} gives
     * for the council tax reduction and one more: working-age Housing Benefit is closed to new
     * claims, and the housing element it was replaced by sits inside Universal Credit, which board
     * card 0048 put out of scope for a pension-age tool. A younger renter is therefore awarded
     * nothing, which UNDERSTATES that plan, and the result note beside it says so.
     *
     * The whole rent is treated as eligible rent: the Local Housing Allowance cap needs a table of
     * area rates this engine does not hold. {@see HousingBenefit} carries that limit and its card.
     *
     * @param  array<string, mixed>  $state
     * @param  ?PensionCreditResult  $award  null when the qualifying-age gate blocked the award,
     *                                       which is also what blocks Housing Benefit
     */
    private function housingBenefitNominal(Household $household, array $state, ?PensionCreditResult $award, int $rentNominal): int
    {
        if ($award === null || $rentNominal <= 0) {
            return 0;
        }

        return HousingBenefit::annualAward(
            Money::fromPence($rentNominal),
            $award,
            $this->meansTestAssessableCapital($household, $state),
            $this->config->benefits->housingSupportUpperCapitalLimit,
            $this->config->statePension->weeksPerYear,
        )->pence;
    }

    /**
     * Assessable capital for the pension-age means test: liquid wealth, plus — when the home is
     * LET (the household lives elsewhere) — its equity, because a let property is not the exempt
     * main residence. So letting it out erodes Pension Credit and can cross the £16k cliff, just
     * as selling does.
     *
     * One definition, two readers: the Pension Credit tariff and the capital-cliff warning are
     * assessed on the same figure, so the award and the disclosure beside it cannot disagree.
     *
     * @param  array<string, mixed>  $state
     */
    private function meansTestAssessableCapital(Household $household, array $state): Money
    {
        $capitalPence = $this->sum($state['cash']) + $this->sum($state['gia']) + $this->sum($state['isa']);
        if ($household->primaryResidence?->isLet) {
            // Valued the way the rules value property capital: market value LESS the notional
            // costs of sale, then less what is secured on it. Until board card 0048 the costs of
            // sale were ignored, which overstated the capital of every household holding property
            // it does not live in — inflating its tariff income and bringing the £16,000 cliff
            // closer than the rules do.
            $capitalPence += CapitalAssessment::propertyCapital(
                Money::fromPence($state['property']),
                Money::fromPence($state['mortgageOutstanding']),
            )->pence;
        }

        return Money::fromPence($capitalPence);
    }

    /**
     * What this year's means test has to DISCLOSE beyond the award itself (board card 0046).
     *
     * Two things, and each was a promise the app was not keeping. The capital cliff — capital
     * above the Housing Benefit / Council Tax Support limit ends both — is built by
     * {@see CapitalAssessment} and, until this collected it, was discarded by its only caller,
     * although METHODOLOGY.md told the reader it was flagged. And a household sitting just above
     * the guarantee is shown nothing at all, when it is the one a caseworker most wants a nil
     * claim from: the engine models Guarantee Credit alone and applies no income disregards, so at
     * that distance its own simplifications can be the whole of the gap.
     *
     * The cliff is assessed in EVERY year, not only from pension age: the £16,000 limit applies to
     * working-age Housing Benefit too, and a plan that frees equity before State Pension age would
     * otherwise cross it in silence.
     *
     * A third, added by board card 0051: the MIXED-AGE COUPLE trap. Where one member is under
     * State Pension age the qualifying-age gate returns no award at all, which is correct and was
     * reported as a plain nil, indistinguishable from a means test that ran and found the
     * household too well off. It is neither, and the loss to a real household is large, so the
     * year says so and names what applies instead.
     *
     * @param  array<string, mixed>  $state
     * @param  ?PensionCreditResult  $award  null when the qualifying-age gate blocked the award
     *                                       entirely (so there is no near miss to report)
     * @param  array<string, bool>  $alive
     * @return list<Warning>
     */
    private function benefitContingencyWarnings(Household $household, array $state, ?PensionCreditResult $award, bool $onGuaranteeCredit, array $alive, int $calendarYear): array
    {
        $warnings = $this->capitalAssessment
            ->assess($this->meansTestAssessableCapital($household, $state), $onGuaranteeCredit)
            ->warnings;

        $overPensionAge = 0;
        $underPensionAge = 0;
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            $calendarYear < $state['spaYear'][$person->id] ? $underPensionAge++ : $overPensionAge++;
        }
        if ($overPensionAge > 0 && $underPensionAge > 0) {
            $warnings[] = new Warning(WarningCode::MIXED_AGE_COUPLE, self::MIXED_AGE_COUPLE_MESSAGE);
        }

        if ($award !== null && $award->isNearMiss()) {
            $warnings[] = new Warning(
                WarningCode::PENSION_CREDIT_NEAR_MISS,
                'Assessable income of '.$award->assessableIncomeWeekly->format().' a week is only just above the '
                .$award->applicableAmountWeekly->format().' a week this household would be topped up to, so the '
                .'forecast awards no Pension Credit. It is within '.PensionCreditResult::nearMissMarginDescription()
                .' of the line, and this forecast models Guarantee Credit only, with no income disregards — so the '
                .'difference can be smaller in reality than it is here.',
            );
        }

        return $warnings;
    }

    /**
     * What Support for Mortgage Interest meets for the household this year, as annual nominal
     * pence — the amount that comes off this year's spending AND is added to the charge on the
     * home. Zero unless Guarantee Credit is actually in payment (the pension-age gate, with no
     * waiting period) and the household still owns the home the charge would sit on.
     *
     * Two eligible costs, met on different rules. The MORTGAGE INTEREST is met at the DWP standard
     * rate on capital up to the pension-age cap, and never above the interest actually charged
     * this year — the payment line for an interest-only or serviced loan, the instalment for an
     * amortising one, and nothing at all for a lifetime mortgage that rolls up unpaid, which is
     * correct: there is no interest liability for DWP to meet. The pension-age HOUSING COSTS
     * (service charge and ground rent) are met in full, less the part of the bucket that buys
     * utilities, which is a personal cost and not an eligible housing one.
     *
     * The housing costs are taken at what the year actually CHARGES for them, on the same rules
     * the buckets above apply: the same real escalation, the same CPI factor, the same survivor
     * multiplier. Reading a figure the household is not charged would let SMI meet a bill nobody
     * pays.
     *
     * @param  array<string, mixed>  $state
     */
    private function supportForMortgageInterestNominal(
        Household $household,
        array $state,
        int $benefitNominal,
        int $mortgagePaymentNominal,
        float $propertyGrowth,
        float $survivor,
        int $yearIndex,
    ): int {
        if ($benefitNominal <= 0 || $household->primaryResidence === null || $state['homeSold']) {
            return 0;
        }

        $profile = $household->expenseProfile;
        $eligibleHousingReal = $profile->propertyCosts()->minus($profile->propertyCostsUtilities())->pence;
        if ($propertyGrowth > 0.0) {
            $eligibleHousingReal += (int) round($eligibleHousingReal * ((1.0 + $propertyGrowth) ** $yearIndex - 1.0));
        }

        return SupportForMortgageInterest::annualAmountMet(
            Money::fromPence($state['mortgageOutstanding']),
            Money::fromPence($mortgagePaymentNominal),
            Money::fromPence((int) round($eligibleHousingReal * $state['spendFactor'] * $survivor)),
        )->pence;
    }

    /**
     * Who is in a LOCAL-AUTHORITY-FUNDED care placement this year, and what fraction of their
     * disability award's care component that leaves in payment ({@see DisabilityBenefitInCare}).
     * A person absent from the returned map is not in a funded placement, so their award runs
     * whole: this year they are either not in care at all, or they are self-funding it.
     *
     * Funding status is settled on the capital the year OPENS with, through the same
     * {@see CareMeansTest::assess()} self-funder line the charge below is built on: a resident
     * whose own assessable capital is at or below the upper limit is one the authority funds.
     * The charge's crossing-year term (capital paid down to the limit) is deliberately not
     * consulted here — it is an annual-grid approximation of a mid-year switch, and reading it
     * would make the benefit's fate depend on a figure that is itself an approximation.
     *
     * v1 flag: the counter resets whenever a spell ends, so a resident who leaves care and
     * returns gets a fresh statutory period. That is the real rule for a genuine break in
     * residence and the wrong one for a short hospital stay, which this engine cannot see.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @return array<string, float> personId => fraction of the care component still paid this year
     */
    private function disabilityCareComponentFractions(Household $household, PathDraws $draws, array &$state, array $alive, int $yearIndex): array
    {
        $aliveCount = count(array_filter($alive));
        $fractions = [];

        foreach ($household->persons as $person) {
            $age = $state['baseAge'][$person->id] + $yearIndex;
            $inFundedPlacement = ($alive[$person->id] ?? false)
                && $draws->careAnnualCost($person->id, $age) > 0
                && ! $this->careMeans->assess(
                    Money::fromPence($this->careAssessableCapital($household, $state, $person->id, $aliveCount)),
                )->selfFunder;

            if (! $inFundedPlacement) {
                $state['laFundedCareYears'][$person->id] = 0;

                continue;
            }

            $prior = $state['laFundedCareYears'][$person->id] ?? 0;
            $fractions[$person->id] = DisabilityBenefitInCare::payableFraction($prior);
            $state['laFundedCareYears'][$person->id] = $prior + 1;
        }

        return $fractions;
    }

    /**
     * The capital assessed against a care-home resident this year (nominal pence). England
     * assesses the individual: the resident's own accounts (cash / GIA / ISA — the engine's
     * accounts are individually owned; pension pots are disregarded as capital, matching the
     * Pension Credit treatment, while drawdown income is assessed as income instead), plus their
     * share of the home where it is assessable at all ({@see careHomeAssessable}). A couple's
     * jointly held home splits equally between them, the individual assessment.
     *
     * @param  array<string, mixed>  $state
     */
    private function careAssessableCapital(Household $household, array $state, string $personId, int $aliveCount): int
    {
        $capital = ($state['cash'][$personId] ?? 0) + ($state['gia'][$personId] ?? 0) + ($state['isa'][$personId] ?? 0);

        if ($this->careHomeAssessable($household, $state, $aliveCount)) {
            $capital += intdiv($this->careHomeEquity($household, $state), max(1, $aliveCount));
        }

        return $capital;
    }

    /**
     * Does the home count as capital in a care financial assessment this year?
     *
     * The statutory property disregard is MANDATORY while the home is occupied by the resident's
     * spouse or civil partner, a relative aged 60 or over, an incapacitated relative, or a child
     * of theirs under 18. The engine covers the first of those by watching whether a partner is
     * still alive, and the rest through {@see Property::$occupiedByQualifyingRelative} — the
     * reader's own statement that somebody on that list lives there (board card 0055, before
     * which a household with a resident older relative was assessed on a home no authority could
     * have charged against). A LET property is nobody's home, so no disregard reaches it and its
     * equity counts, the same rule the Pension Credit test applies.
     *
     * @param  array<string, mixed>  $state
     */
    private function careHomeAssessable(Household $household, array $state, int $aliveCount): bool
    {
        $home = $household->primaryResidence;
        if ($home === null || $state['homeSold']) {
            return false;
        }

        if ($home->isLet) {
            return true;
        }

        return $aliveCount === 1 && ! $home->occupiedByQualifyingRelative;
    }

    /**
     * Has a lifetime mortgage on this home fallen due this year because the LAST surviving
     * borrower has moved permanently into residential care? (Board card 0056.)
     *
     * A roll-up balance ({@see Property::$mortgageRollUpRate}) is an equity-release plan, and
     * every standard one matures on the last borrower's death, sale of the home OR permanent
     * entry into long-term care: the home is sold and the lender is paid first. Only a roll-up
     * plan is called in — an ordinary serviced or repayment mortgage carries no such term, and a
     * balance already redeemed has nothing to call.
     *
     * "Last surviving borrower" is every LIVING member being in care this year. While one of them
     * is still living in the home the contract has not matured, so the home is kept.
     *
     * v1 flag: the engine models a care spell, not its permanence, so any modelled year in care
     * is treated as a permanent placement. That is the adverse reading and the usual one — a
     * modelled spell runs to death.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function equityReleaseRedeemedByCare(Household $household, array $state, PathDraws $draws, array $alive, int $yearIndex): bool
    {
        if ($state['mortgageRollUpRate'] === null || $state['mortgageOutstanding'] <= 0) {
            return false;
        }

        $living = 0;
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            $living++;
            if ($draws->careAnnualCost($person->id, $state['baseAge'][$person->id] + $yearIndex) <= 0) {
                return false;
            }
        }

        return $living > 0;
    }

    /**
     * The household's equity in the home for a care assessment: its share of the value less
     * EVERYTHING secured on it. The deferred payment balance is netted for the same reason the
     * mortgage is — money the authority has already lent against the bricks is not capital the
     * resident can spend a second time, and leaving it in would let a deferred year inflate the
     * next year's charge.
     *
     * FLAGGED: the Support for Mortgage Interest charge is NOT netted here, although it is
     * secured on the same home and the estate and the wealth line both net it. That is a
     * pre-existing divergence, out of card 0055's scope, and is board card 0130.
     *
     * @param  array<string, mixed>  $state
     */
    private function careHomeEquity(Household $household, array $state): int
    {
        return max(0, $this->netHomeValue($household, $state)->pence - $state['mortgageOutstanding'] - $state['deferredCareBalance']);
    }

    /**
     * This year's own DB pension income for one person, nominal pence. Each scheme carries its
     * OWN running factor ({@see growState}), keyed by its position in the household's pension
     * list, because escalation is a per-scheme rule: one pre-1997 slice frozen for life and one
     * CPI-linked slice in the same household must not share a factor.
     *
     * The year the member REACHES normal retirement age is a part year: the pension starts on that
     * birthday, so it pays the months after it and no more ({@see startFraction}).
     *
     * @param  array<array-key, float>  $dbFactors
     */
    private function dbIncome(Household $household, Person $person, int $age, array $dbFactors): int
    {
        $total = 0;
        foreach ($household->pensions as $key => $pension) {
            if ($pension instanceof DbPension && $pension->ownerId === $person->id && $age >= $pension->normalRetirementAge) {
                $fraction = $age === $pension->normalRetirementAge
                    ? self::startFraction((int) $person->dob->format('n'))
                    : 1.0;
                $total += (int) round($this->commutedAnnualPence($pension) * ($dbFactors[$key] ?? 1.0) * $fraction);
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
    /** @param  array<array-key, float>  $dbFactors */
    private function commutationLumpSumNominal(Household $household, string $pid, int $age, array $dbFactors): int
    {
        $total = 0;
        foreach ($household->pensions as $key => $pension) {
            if ($pension instanceof DbPension
                && $pension->ownerId === $pid
                && $pension->commutationLumpSum !== null
                && $pension->commutationLumpSum->pence > 0
                && $age === $pension->normalRetirementAge) {
                $total += (int) round($pension->commutationLumpSum->pence * ($dbFactors[$key] ?? 1.0));
            }
        }

        return $total;
    }

    private function statePensionIncome(Household $household, string $pid, int $calendarYear, int $spClaimYear, int $spaMonth, float $spFactor): int
    {
        // Nothing is paid before the claim year: at State Pension age if undeferred, later by the
        // deferral period if deferring — the forgone income is what makes deferral a genuine
        // trade-off (an early death after deferring is a net lifetime loss), not a free uplift.
        if ($calendarYear < $spClaimYear) {
            return 0;
        }
        // The claim year is a PART year: entitlement begins on the State Pension age date (shifted
        // whole years by any deferral, so the month is the same either way), and a pension starting
        // in November pays two months, not twelve.
        $fraction = $calendarYear === $spClaimYear ? self::startFraction($spaMonth) : 1.0;
        foreach ($household->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $pid) {
                $base = $pension->weeklyForecast !== null
                    ? $this->statePension->fromWeeklyForecast($pension->weeklyForecast, $pension->deferralWeeks)
                    : $this->statePension->fromQualifyingYears($pension->qualifyingYears ?? 0, $pension->deferralWeeks);

                return (int) round($base->annual->pence * $spFactor * $fraction);
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

    /**
     * This person's income streams this year (nominal pence), on one side of the taxable divide.
     * $only narrows it to a single kind, which is how the disability CARE component is picked out
     * of the tax-free total without a second copy of the age-window rule.
     */
    private function incomeStreamsNominal(Household $household, string $pid, int $age, float $cumInflation, bool $taxable, ?IncomeStreamType $only = null): int
    {
        $total = 0;
        foreach ($household->incomeStreams as $stream) {
            if ($stream->ownerId !== $pid || $stream->taxable !== $taxable || ($only !== null && $stream->type !== $only)) {
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
     * The household's GROSS rental income this year (nominal), split by the owner who receives it.
     * Only {@see IncomeStreamType::Rental} streams count, so a generic "other" income is not
     * mistaken for rent. Split rather than totalled because the letting costs it carries are
     * deducted from the owner's own taxable income, and the UK taxes people individually.
     *
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     * @return array<string, int> ownerId => gross rent in nominal pence
     */
    private function rentalIncomePerOwner(Household $household, array $alive, array $ages, float $cumInflation): array
    {
        $gross = [];
        foreach ($household->incomeStreams as $stream) {
            if ($stream->type !== IncomeStreamType::Rental || ! ($alive[$stream->ownerId] ?? false)) {
                continue;
            }
            $age = $ages[$stream->ownerId] ?? 0;
            if ($age < $stream->startAge || ($stream->endAge !== null && $age > $stream->endAge)) {
                continue;
            }
            $gross[$stream->ownerId] = ($gross[$stream->ownerId] ?? 0) + ($stream->inflationLinked
                ? (int) round($stream->grossAnnual->pence * $cumInflation)
                : $stream->grossAnnual->pence);
        }

        return $gross;
    }

    /**
     * What letting the home COSTS each owner this year (nominal pence), to be taken off their gross
     * rent. Empty unless the primary residence is let and still owned.
     *
     * Until board card 0030 a let property earned its rent GROSS: no agent, no empty weeks between
     * tenants, no repairs or safety certificates, and a service charge charged as the household's
     * own shopping while the whole rent was taxed as profit. Two deductions fix that, and both are
     * ordinary allowable letting expenses, so taking them off here corrects the cash the household
     * banks AND the profit it is taxed on in one place:
     *
     *  - the percentage costs, {@see Property::lettingCostRate()} (management, void, maintenance);
     *  - the let home's SERVICE CHARGE, its ground rent and its levies, apportioned across the
     *    owners pro rata to their gross rent. That bill is still charged as spend (they really do
     *    pay it, so the cash is unchanged); what changes is that it stops being taxed as though
     *    they had not.
     *
     * Each owner's deduction is CAPPED at their own gross rent. Expenses above the rent are a
     * rental loss, which in law is carried forward against future rental profit rather than set
     * against other income; the engine does not model the carry-forward, so the year floors at nil
     * profit rather than sheltering a pension it could not shelter.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     * @return array<string, int> ownerId => pence to deduct from that owner's rental income
     */
    private function lettingCostsPerOwner(Household $household, array $state, array $alive, array $ages, float $cumInflation, int $yearIndex): array
    {
        $home = $household->primaryResidence;
        if ($home === null || ! $home->isLet || $state['homeSold']) {
            return [];
        }

        $gross = $this->rentalIncomePerOwner($household, $alive, $ages, $cumInflation);
        $total = array_sum($gross);
        if ($total <= 0) {
            return [];
        }

        $rate = $home->lettingCostRate()->asFraction();
        $expense = $this->propertyCostsNominal($household, $state, $alive, $yearIndex);

        $costs = [];
        foreach ($gross as $ownerId => $rent) {
            $costs[$ownerId] = min($rent, (int) round($rent * $rate) + (int) round($expense * $rent / $total));
        }

        return $costs;
    }

    /**
     * The home-ownership cost bucket (service charge, ground rent, levies) in nominal pence this
     * year: the bucket, compounded at its own real growth for $yearIndex years, then carried up by
     * the CPI and survivor factors every spend line rides. It mirrors the arithmetic the spend
     * target applies to the same bucket a few dozen lines below, and reads the same two figures off
     * {@see ExpenseProfile}, so a re-sourced bucket or rate moves both together.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     */
    private function propertyCostsNominal(Household $household, array $state, array $alive, int $yearIndex): int
    {
        $profile = $household->expenseProfile;
        $bucket = $profile->propertyCosts()->pence;
        if ($bucket <= 0) {
            return 0;
        }

        $survivor = count(array_filter($alive)) === 1 ? $profile->survivorSpendFactor->asFraction() : 1.0;
        $escalated = $bucket * ((1.0 + $profile->propertyCostsRealGrowth()->asFraction()) ** $yearIndex);

        return (int) round($escalated * $state['spendFactor'] * $survivor);
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
        $lsa = $this->config->pension->lumpSumAllowance->pence;
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
                // Re-read per instruction rather than carrying a running local: the ledger it reads
                // is updated below, so the two cannot drift, and asking the same helper the ad-hoc
                // closure asks is what keeps the inherited-pot rule from living in only one route.
                $lsaRemaining = self::lsaHeadroom($lsa, $state['lsaUsed'][$pid], $pot);

                if ($instruction->kind->triggersMpaa()) {
                    // Flexible access: from now on this member's money-purchase contributions are
                    // capped at the MPAA. The three sites that can trigger it (here and the two
                    // ad-hoc draw closures in fundShortfall) all set it through one helper, so a
                    // planned instruction and an ad-hoc draw cannot apply the rule differently.
                    // {@see triggerFlexibleAccess}, {@see contributionHeadroom}.
                    $this->triggerFlexibleAccess($state, $pid, $pot);
                }

                switch ($instruction->kind) {
                    case WithdrawalKind::Ufpls:
                        // A UFPLS pays out everything it crystallises, so it leaves no drawdown
                        // residue behind — but it can be taken out of one, in which case that part
                        // of it is all taxable.
                        [$tf, $tx] = self::ufplsSplit($amount, $lsaRemaining, $pclsRate, $pot['crystallised'] ?? 0);
                        $taxFree += $tf;
                        $taxable += $tx;
                        $this->drawFromPot($pot, $amount);
                        $state['lsaUsed'][$pid] += $tf;
                        break;
                    case WithdrawalKind::Pcls:
                        // amount = tax-free cash taken; the rest stays invested. On an inherited pot
                        // the headroom is nil, so nothing is taken and nothing is charged: there is
                        // no tax-free cash in a beneficiary drawdown to instruct. Only UNCRYSTALLISED
                        // money can produce tax-free cash, so a second lump sum cannot take a quarter
                        // of the residue the first one left behind — the planned route's half of the
                        // same rule the split applies on the ad-hoc one.
                        $tf = min($amount, $lsaRemaining, max(0, $pot['value'] - ($pot['crystallised'] ?? 0)));
                        $taxFree += $tf;
                        // Taking £X of tax-free cash CRYSTALLISES £X / 25% of the pot: £X is paid out
                        // and the other three quarters are designated to drawdown. That residue has
                        // had its quarter, so a later draw from this pot cannot take another one
                        // ({@see ufplsSplit}). Without this the pot's whole remaining balance was
                        // still treated as uncrystallised and a fill-the-bands draw split it 25/75
                        // all over again, bounded only by the allowance ledger.
                        //
                        // Crystallise the WHOLE slice first, then pay the cash out of it: {@see
                        // drawFromPot} takes crystallised money first, so debiting before designating
                        // took the cash out of the residue a PREVIOUS lump sum had left behind. A
                        // second £X then turned £X of drawdown money uncrystallised again and handed
                        // the next fill-the-bands draw a quarter of it, compounding per lump sum.
                        // Pinned by test_two_lump_sums_crystallise_as_much_as_one_of_twice_the_size.
                        if ($tf > 0) {
                            $pot['crystallised'] = min($pot['value'], ($pot['crystallised'] ?? 0) + (int) round($tf / $pclsRate));
                            $this->drawFromPot($pot, $tf);
                        }
                        $state['lsaUsed'][$pid] += $tf;
                        break;
                    case WithdrawalKind::DrawdownIncome:
                        $taxable += $amount;
                        $this->drawFromPot($pot, $amount);
                        break;
                }
            }
        }

        return ['taxable' => $taxable, 'taxFree' => $taxFree];
    }

    /**
     * Take $amount out of a pot. THE one place a pot is debited, so the crystallised balance cannot
     * be kept up to date at five of the six draw sites and forgotten at the sixth.
     *
     * CRYSTALLISED money goes first. It is the cautious order — that money has already had its
     * tax-free quarter, so drawing it first means the quarter is not handed out again early — and it
     * matches what a member with a drawdown fund and an uncrystallised pot beside it would be
     * charged. The alternative orders (pro-rata, or uncrystallised first) both hand out more
     * tax-free cash sooner.
     *
     * @param  array<string, mixed>  $pot
     */
    private function drawFromPot(array &$pot, int $amount): void
    {
        $pot['value'] -= $amount;
        $pot['crystallised'] = min(max(0, $pot['value']), max(0, ($pot['crystallised'] ?? 0) - max(0, $amount)));
    }

    /**
     * How much tax-free Lump Sum Allowance a draw from THIS pot may still use. The companion to
     * {@see ufplsSplit}: the split says what share of a draw is tax-free, this says whose allowance
     * pays for it. Both routes into the split — a planned WithdrawalInstruction and the ad-hoc
     * FillBands closure — ask this, so the rule below cannot be written into one of them and
     * missed in the other, which is exactly how it was found.
     *
     * An INHERITED pot has NO headroom. Beneficiary drawdown is the deceased's fund under its own
     * regime ({@see collectDeathInServiceBenefit} states it for the lump-sum form), not a pension
     * of the heir's: there is no tax-free quarter in it, and the heir's own allowance (and, through
     * deathBenefit['lsaUsed'], their death-benefit allowance) must not pay for it. Zero headroom
     * makes the split all-taxable, which is exactly the fully-taxable draw the model has always
     * charged on an inherited pot.
     *
     * Public static so it is unit-tested directly, like the split it feeds.
     *
     * @param  array<string, mixed>  $pot
     */
    public static function lsaHeadroom(int $lumpSumAllowance, int $lsaUsed, array $pot): int
    {
        if ($pot['inherited'] ?? false) {
            return 0;
        }

        return max(0, $lumpSumAllowance - $lsaUsed);
    }

    /**
     * Split a gross UFPLS withdrawal into its tax-free and taxable parts: 25% is tax-free, but
     * only while the member's Lump Sum Allowance lasts, beyond which the whole withdrawal is
     * taxable. THE one home for the rule, so a planned WithdrawalInstruction and an ad-hoc
     * FillBands draw can never diverge (they used to be two copies of the same three lines).
     * How much allowance is left to spend is the other half of that, and has its own one home:
     * {@see lsaHeadroom}. Public static so the split is unit-tested directly at the boundary.
     *
     * $crystallised is how much of the pot is already designated to drawdown. That money has had its
     * tax-free cash and gets no second quarter, and it is drawn first ({@see drawFromPot}), so the
     * first $crystallised pence of any draw are wholly taxable and only what is left of the draw is
     * split. Nil (the default) is the ordinary wholly-uncrystallised pot.
     *
     * @return array{0: int, 1: int} [taxFree, taxable], both in pence
     */
    public static function ufplsSplit(int $gross, int $lsaRemaining, float $pclsRate, int $crystallised = 0): array
    {
        $uncrystallised = max(0, $gross - max(0, $crystallised));
        $taxFree = min((int) floor($uncrystallised * $pclsRate), max(0, $lsaRemaining));

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
     *
     * A crystallised slice comes off the front, wholly taxable and pound for pound against the room
     * ({@see ufplsSplit}); once it is spent the two-cap solve applies to whatever room is left.
     */
    public static function maxUfplsGross(int $taxableRoom, int $lsaRemaining, float $pclsRate, int $crystallised = 0): int
    {
        if ($taxableRoom <= 0) {
            return 0;
        }
        $crystallised = max(0, $crystallised);
        if ($crystallised > 0) {
            return $taxableRoom <= $crystallised
                ? $taxableRoom
                : $crystallised + self::maxUfplsGross($taxableRoom - $crystallised, $lsaRemaining, $pclsRate);
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
     * One planned annuity, flattened for the hot loop. $source is the wrapper the money comes out
     * of: NULL for a pension annuity (the owner's DC pots) or the account type for a purchased life
     * annuity. The rate is the EFFECTIVE one, so the enhanced uplift is applied in the DTO that
     * owns it and never restated here.
     *
     * @return array<string, mixed>
     */
    private static function annuityState(string $ownerId, AnnuityPurchase $a, ?AccountType $source): array
    {
        return [
            'ownerId' => $ownerId,
            'atAge' => $a->atAge,
            'incomeFromAge' => $a->incomeStartAge(),
            'amount' => $a->amount->pence,
            'rate' => $a->effectiveRate()->asFraction(),
            'escalation' => $a->escalation,
            'survivorFraction' => $a->survivorFraction?->asFraction(),
            'source' => $source,
            'purchased' => false,
            'active' => false,
            'baseIncomeNominal' => 0,
            // The exempt capital element of one year's payment, fixed in nominal pence for the life
            // of the annuity ({@see PurchasedLifeAnnuity}). Nil for a pension annuity, which is
            // taxable in full.
            'exemptNominal' => 0,
            'purchaseCumInflation' => 1.0,
        ];
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
     * A purchase whose `source` is an account type is a PURCHASED LIFE ANNUITY (board card 0060):
     * the money comes out of that named wrapper instead of the pots, and the payments it buys are
     * taxed on their interest element only — the exempt capital element is settled once, here, at
     * the age the income starts. Selling a general investment account to fund one realises a gain,
     * which is returned so the year charges CGT on it exactly as any other disposal.
     *
     * A purchase out of a DC POT crystallises the money it takes, so a quarter of it comes back as
     * a tax-free lump sum and only the balance buys the income (board card 0065). That cash is
     * returned rather than banked here, because the caller owns the year's income assembly and is
     * where a tax-free sum is attributed to its owner and filed under its source.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @return array{gains: array<string, int>, taxFreeCash: array<string, int>} per person, pence:
     *                                                                           the GIA gain a purchase realised, and the tax-free lump sum it paid out
     */
    private function processAnnuityPurchases(Household $household, array &$state, int $yearIndex, int $calendarYear, array $alive, float $cumInflation): array
    {
        $realisedGains = [];
        $taxFreeCash = [];

        foreach ($state['annuities'] as &$annuity) {
            if ($annuity['purchased']) {
                continue;
            }
            $pid = $annuity['ownerId'];
            $age = $state['baseAge'][$pid] + $yearIndex;
            if ($age < $annuity['atAge'] || ! ($alive[$pid] ?? false)) {
                continue;
            }

            if ($annuity['source'] === null) {
                $draw = $this->drawAnnuityPriceFromPots($state, $pid, $annuity['amount']);
                $bought = $draw['annuitised'];
                if ($draw['taxFree'] > 0) {
                    $taxFreeCash[$pid] = ($taxFreeCash[$pid] ?? 0) + $draw['taxFree'];
                }
            } else {
                $bought = $this->drawAnnuityPriceFromAccount($state, $pid, $annuity['source'], $annuity['amount'], $realisedGains);
            }

            $annuity['purchased'] = true;
            if ($bought > 0) {
                $annuity['active'] = true;
                $annuity['baseIncomeNominal'] = (int) round($bought * $annuity['rate']);
                $annuity['purchaseCumInflation'] = $cumInflation;

                if ($annuity['source'] !== null) {
                    // The exempt capital element: the price spread over the buyer's expected
                    // remaining life at the age the income starts, fixed in money for the life of
                    // the annuity. Both the rule and the expectancy are read from the classes that
                    // own them, so neither figure is restated here.
                    $startAge = $annuity['incomeFromAge'];
                    $sex = $household->person($pid)?->sex;
                    $expectancy = $sex === null ? 0.0 : $this->lifeTable()->lifeExpectancy($sex, $startAge, $calendarYear + ($startAge - $age));
                    $annuity['exemptNominal'] = PurchasedLifeAnnuity::capitalElementPerYear(
                        Money::fromPence($bought),
                        $expectancy,
                        Money::fromPence($annuity['baseIncomeNominal']),
                    )->pence;
                }
            }
        }
        unset($annuity);

        return ['gains' => $realisedGains, 'taxFreeCash' => $taxFreeCash];
    }

    /**
     * Draw an annuity's purchase price across the owner's DC pots, in order, CRYSTALLISING each
     * slice as it goes: a quarter of the uncrystallised part comes out as a tax-free lump sum and
     * only the balance buys the annuity (board card 0065). That is what actually happens when a
     * pot is annuitised, and the engine used to hand the insurer the whole amount and tax every
     * penny of the income that came back, which under-rated annuitising against drawdown twice
     * over in the same direction.
     *
     * The split is {@see ufplsSplit}, the same rule and the same lump-sum-allowance ledger a UFPLS
     * uses, because it is the same event: money crystallised, a quarter paid out tax free. Only the
     * destination of the other three quarters differs. So an already-crystallised slice, and an
     * inherited pot (no headroom at all), each yield no lump sum here exactly as they do there.
     *
     * Buying a lifetime annuity is NOT flexible access, so it does not trigger the MPAA.
     *
     * @param  array<string, mixed>  $state
     * @return array{taxFree: int, annuitised: int} nominal pence
     */
    private function drawAnnuityPriceFromPots(array &$state, string $pid, int $needed): array
    {
        $lsa = $this->config->pension->lumpSumAllowance->pence;
        $pclsRate = $this->config->pension->pclsRate->asFraction();
        $taxFree = 0;
        $annuitised = 0;

        foreach ($state['pots'][$pid] as &$pot) {
            if ($needed <= 0) {
                break;
            }
            $take = min($needed, $pot['value']);
            if ($take <= 0) {
                continue;
            }
            [$slice, $rest] = self::ufplsSplit(
                $take,
                self::lsaHeadroom($lsa, $state['lsaUsed'][$pid], $pot),
                $pclsRate,
                $pot['crystallised'] ?? 0,
            );
            $this->drawFromPot($pot, $take);
            $state['lsaUsed'][$pid] += $slice;
            $needed -= $take;
            $taxFree += $slice;
            $annuitised += $rest;
        }
        unset($pot);

        return ['taxFree' => $taxFree, 'annuitised' => $annuitised];
    }

    /**
     * Draw an annuity's purchase price out of ONE named non-pension wrapper — the account the
     * purchase hangs off. Capped at what is in it: an account that cannot fund the whole price
     * buys a smaller annuity rather than conjuring money. A GIA sale realises its share of the
     * unrealised gain, accumulated into $realisedGains so the year's CGT charge sees it.
     *
     * @param  array<string, int>  $realisedGains
     */
    private function drawAnnuityPriceFromAccount(array &$state, string $pid, AccountType $source, int $needed, array &$realisedGains): int
    {
        $key = match ($source) {
            AccountType::Cash, AccountType::PremiumBonds => 'cash',
            AccountType::Gia => 'gia',
            AccountType::Isa => 'isa',
        };

        $take = min($needed, $state[$key][$pid] ?? 0);
        if ($take <= 0) {
            return 0;
        }

        if ($key === 'gia') {
            [$gainSlice, $basisConsumed] = self::disposeGiaSlice($state['gia'][$pid], $state['giaBasis'][$pid], $take);
            $realisedGains[$pid] = ($realisedGains[$pid] ?? 0) + $gainSlice;
            $state['giaBasis'][$pid] -= $basisConsumed;
        }
        $state[$key][$pid] -= $take;

        return $take;
    }

    /** The mortality table behind the purchased-life-annuity capital element, built once per path. */
    private function lifeTable(): CohortLifeTable
    {
        return $this->lifeTable ??= new CohortLifeTable;
    }

    /**
     * This year's annuity income, per person, in nominal pence. While the annuitant lives they
     * receive the full income (taxed on them); after they die a joint annuity continues at its
     * survivor fraction to the first living person (the surviving partner), while a single-life
     * annuity stops. A level annuity (escalation None) pays a flat nominal income that falls in
     * real terms; any other basis escalates the income with inflation since purchase — the same
     * proxy the engine uses for DB escalation in payment.
     *
     * A DEFERRED annuity (board card 0060) pays nothing until the annuitant reaches the age the
     * income was bought to start at; the money left the pot or the account at the purchase age.
     *
     * Each person's figure is split into the whole payment and the part of it exempt from income
     * tax — the capital element of a purchased life annuity, which is a return of the buyer's own
     * money. The exempt part is still INCOME for the Pension Credit and care means tests, so it is
     * reported apart rather than removed: only the tax pass takes it off.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     * @return array<string, array{income: int, exempt: int}> personId => nominal annuity income
     */
    private function annuityIncomeNominal(array $state, Household $household, array $alive, array $ages, float $cumInflation): array
    {
        $income = [];
        $add = static function (string $pid, int $amount, int $exempt) use (&$income): void {
            $income[$pid] ??= ['income' => 0, 'exempt' => 0];
            $income[$pid]['income'] += $amount;
            $income[$pid]['exempt'] += $exempt;
        };

        foreach ($state['annuities'] as $annuity) {
            if (! $annuity['active']) {
                continue;
            }
            // A DEFERRED annuity pays nothing at all — to the annuitant or to a survivor — until
            // the annuitant would have reached the age the income was bought to start at. $ages
            // holds every member's age this year, the dead included, so a death inside the
            // deferral period leaves the survivor with nothing, which is the adverse reading and
            // the usual contract (value protection is not modelled).
            if (($ages[$annuity['ownerId']] ?? 0) < $annuity['incomeFromAge']) {
                continue;
            }

            $factor = $annuity['escalation'] === PensionEscalationBasis::None
                ? 1.0
                : $cumInflation / $annuity['purchaseCumInflation'];
            $amount = (int) round($annuity['baseIncomeNominal'] * $factor);
            if ($amount <= 0) {
                continue;
            }
            // The exempt capital element is a fixed sum for the life of the annuity, so an
            // escalating annuity's exempt PROPORTION falls as its payments grow.
            $exempt = min($annuity['exemptNominal'], $amount);

            if ($alive[$annuity['ownerId']] ?? false) {
                $add($annuity['ownerId'], $amount, $exempt);
            } elseif ($annuity['survivorFraction'] !== null) {
                $survivor = $this->firstLiving($household, $alive);
                if ($survivor !== null) {
                    $fraction = $annuity['survivorFraction'];
                    $add($survivor, (int) round($amount * $fraction), (int) round($exempt * $fraction));
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
     * @param  array<array-key, float>  $dbFactors
     * @return array<string, int> personId => nominal taxable survivor DB income
     */
    private function survivorDbIncomeNominal(Household $household, array $alive, array $dbFactors): array
    {
        $income = [];
        foreach ($household->pensions as $key => $pension) {
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
            $full = (int) round($this->commutedAnnualPence($pension) * ($dbFactors[$key] ?? 1.0));
            $income[$survivor] = ($income[$survivor] ?? 0)
                + (int) round($full * $pension->spousePensionFraction->asFraction());
        }

        return $income;
    }

    /**
     * Class 1 NI on this person's employment earnings, or zero if not employed, past planned
     * retirement, or past State Pension age (NI ends at SPA). The YEAR State Pension age falls in
     * is part liable: NI is due on the earnings before that date.
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

        // NI stops ON the day State Pension age is reached, not on the 1 January before it, so the
        // months worked earlier in that year are still liable. The liable slice is the part of the
        // year worked that also falls before that date: min of the two fractions, which leaves every
        // earlier year at the work fraction and every later one at nothing.
        $calendarYear = $state['baseYear'] + $yearIndex;
        $spaYear = $state['spaYear'][$pid];
        $liable = match (true) {
            $calendarYear > $spaYear => 0.0,
            $calendarYear === $spaYear => min($fraction, $state['spaMonth'][$pid] / 12.0),
            default => $fraction,
        };
        if ($liable <= 0.0) {
            return 0;
        }

        // The slice already excludes everything after State Pension age, so the calculator is asked
        // about a still-liable earner. (v1 limit: thresholds are annual, where real NI is assessed
        // per pay period, so a part year is charged against a whole year's threshold, board card 0096.)
        $earnings = (int) round($person->grossSalary->pence * $state['salaryFactor'][$person->id] * $liable);

        return $this->ni->onEmploymentEarnings(Money::fromPence($earnings), hasReachedStatePensionAge: false, category: $person->niCategory)->total->pence;
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
     * The fraction of the calendar year an income is paid when it STARTS part way through it, on a
     * date falling in month $month: the complement of {@see workFraction}, because the income
     * replacing a salary begins where the salary stops. A pension starting in March pays 9/12 of
     * the year, not 12/12 — the transition year is the one an affordability cliff shows in, so
     * paying a whole year of it flatters exactly the year that must not be flattered.
     */
    private static function startFraction(int $month): float
    {
        return (12 - $month) / 12.0;
    }

    /**
     * Draw assets to cover $shortfall (nominal) in the strategy's order, grossing up
     * pension withdrawals for tax. Returns the net funded, any extra tax incurred,
     * and how much was drawn from pensions (gross) vs other assets — so the cashflow
     * ladder can show where the shortfall money came from.
     *
     * `fromPensionTaxFree` is the part of `fromPension` that arrived free of tax (the UFPLS-style
     * quarter, while the Lump Sum Allowance lasts: {@see ufplsSplit}). It is a SUBSET of
     * `fromPension`, never a second sum, so the caller files it on the tax-free cash line and only
     * the balance on the taxable drawdown line, which is what lets a reader add up taxable income
     * off the cashflow ladder and get the figure the year's tax was computed on (board card 0074).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $taxablePerPerson  nominal NON-SAVINGS taxable income per person
     * @param  array<string, int>  $savingsPerPerson  nominal savings income (interest) per person
     * @param  array<string, int>  $dividendsPerPerson  nominal dividend income per person
     * @return array{funded: int, extraTax: int, fromPension: int, fromPensionTaxFree: int, fromAssets: int}
     */
    private function fundShortfall(Household $household, ForecastSettings $settings, array &$state, array $alive, array $ages, array $taxablePerPerson, array $savingsPerPerson, array $dividendsPerPerson, int $shortfall, float $thresholdFactor = 1.0, bool $onGuaranteeCredit = false, array $seedGains = []): array
    {
        $remaining = $shortfall;
        $funded = 0;
        $extraTax = 0;
        $fromPension = 0; // gross pension withdrawn to meet the shortfall
        $fromPensionTaxFree = 0; // the part of that gross which was tax-free cash (subset)
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

        // Each person's non-savings income AS THIS YEAR'S DRAWING LEAVES IT. A strategy draws
        // pension in more than one pass — PensionAware twice, FillBands three times, and either
        // once more to pay the CGT below — and every pass must start from where the last one
        // finished. Restarting each from the pre-drawdown figure priced every later draw in a
        // band the person had already left, which is the same fault as costing it without their
        // savings (board card 0037). Held apart from $taxablePerPerson, which stays the
        // PRE-drawdown income the CGT band split and the means test were assessed on.
        $drawnTaxable = $taxablePerPerson;

        // One person's whole taxable income, given where their NON-SAVINGS income has reached.
        // Every pricing of a pension draw goes through this, so a draw can never be costed
        // against a slice of an income the rest of which sits above it in the band stack
        // (board card 0037). The band-filling CAPS below stay on non-savings income, because
        // that is the strategy's own question — which band the pension itself should fill.
        $incomeOf = fn (string $pid, int $nonSavings): TaxableIncome => new TaxableIncome(
            Money::fromPence($nonSavings),
            Money::fromPence($savingsPerPerson[$pid] ?? 0),
            Money::fromPence($dividendsPerPerson[$pid] ?? 0),
        );

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
        $drawPension = function (?int $taxableLimit) use (&$state, &$remaining, &$funded, &$extraTax, &$fromPension, &$drawnTaxable, $alive, $ages, $household, $incomeOf, $thresholdFactor): void {
            foreach ($household->persons as $person) {
                if ($remaining <= 0) {
                    return;
                }
                if (! $alive[$person->id]) {
                    continue;
                }
                $alreadyTaxable = $drawnTaxable[$person->id];
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
                    $existing = $incomeOf($person->id, $alreadyTaxable);
                    $gross = $this->grossUpPension($remaining, $existing, $cap, $thresholdFactor);
                    if ($gross <= 0) {
                        continue;
                    }
                    $taxDelta = $this->marginalTax($existing, $gross, $thresholdFactor);
                    $net = $gross - $taxDelta;
                    $this->drawFromPot($pot, $gross);
                    // Taxable pension income out of the member's OWN money-purchase pot is flexible
                    // access, the same event {@see WithdrawalKind::DrawdownIncome} triggers on: it
                    // caps their future contributions at the MPAA ({@see contributionHeadroom}).
                    // Set here as well as in $drawPensionUfpls so the restriction does not depend
                    // on which drawdown strategy is running — otherwise the optimiser compared its
                    // candidates on unequal terms, only FillBands carrying the cap.
                    $this->triggerFlexibleAccess($state, $person->id, $pot);
                    $remaining -= $net;
                    $funded += $net;
                    $extraTax += $taxDelta;
                    $fromPension += $gross;
                    $alreadyTaxable += $gross;
                }
                unset($pot);
                $drawnTaxable[$person->id] = $alreadyTaxable;
            }
        };

        // The same draw, taken UFPLS-style: 25% of each withdrawal is tax-free (while the Lump
        // Sum Allowance lasts) and only the rest is taxable income. This is what a retiree
        // drawing ad-hoc from an uncrystallised pot actually does, and taxing 100% of it instead
        // mispriced pension wealth against every other asset. FillBands only: the SPLIT lives here
        // and nowhere else, so TaxEfficient / PensionAware and the HMRC worked examples keep their
        // figures. ($drawPension is no longer byte-identical — it sets the MPAA trigger too, which
        // is deliberate and is DECISIONS 2026-08-19 item 4: flexible access belongs to the draw,
        // not to the draw order. It moves no figure the worked examples assert.)
        //
        // Two caps bind at once: the band being filled ($taxableLimit, which only the TAXABLE
        // part consumes, so the draw is ~a third larger for the same taxable income) and the
        // person's remaining Lump Sum Allowance. {@see maxUfplsGross} solves both. With no
        // allowance left the split is all-taxable, so this degrades exactly to $drawPension.
        $drawPensionUfpls = function (?int $taxableLimit) use (&$state, &$remaining, &$funded, &$extraTax, &$fromPension, &$fromPensionTaxFree, &$drawnTaxable, $alive, $ages, $household, $incomeOf, $thresholdFactor): void {
            $pclsRate = $this->config->pension->pclsRate->asFraction();
            $lsa = $this->config->pension->lumpSumAllowance->pence;

            foreach ($household->persons as $person) {
                if ($remaining <= 0) {
                    return;
                }
                if (! $alive[$person->id]) {
                    continue;
                }
                $alreadyTaxable = $drawnTaxable[$person->id];
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
                    // Whose allowance, if any, this pot's tax-free quarter may spend — the same
                    // question {@see plannedWithdrawals} asks, through the same helper, so an
                    // inherited pot cannot be excluded on one route and not the other. Pinned by
                    // PathProjectorTest::test_a_fill_bands_draw_from_an_inherited_pot_takes_no_tax_free_quarter.
                    $lsaRemaining = self::lsaHeadroom($lsa, $state['lsaUsed'][$person->id], $pot);
                    // Any part of the pot already designated to drawdown has had its tax-free cash,
                    // so it is drawn first and taxed in full ({@see ufplsSplit}, {@see drawFromPot}).
                    $crystallised = $pot['crystallised'] ?? 0;
                    $cap = $pot['value'];
                    if ($taxableLimit !== null) {
                        $cap = min($cap, self::maxUfplsGross($taxableLimit - $alreadyTaxable, $lsaRemaining, $pclsRate, $crystallised));
                    }
                    if ($cap <= 0) {
                        continue;
                    }

                    // Gross up so the after-tax cash meets the need, on the same iteration as
                    // grossUpPension, where only the taxable part carries tax.
                    $existing = $incomeOf($person->id, $alreadyTaxable);
                    $gross = $remaining;
                    for ($i = 0; $i < 8; $i++) {
                        $tax = $this->marginalTax($existing, self::ufplsSplit($gross, $lsaRemaining, $pclsRate, $crystallised)[1], $thresholdFactor);
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

                    [$taxFree, $taxablePart] = self::ufplsSplit($gross, $lsaRemaining, $pclsRate, $crystallised);
                    $taxDelta = $this->marginalTax($existing, $taxablePart, $thresholdFactor);
                    $net = $gross - $taxDelta;
                    $this->drawFromPot($pot, $gross);
                    $state['lsaUsed'][$person->id] += $taxFree;
                    // A UFPLS from the member's own pot is flexible access: it caps their future
                    // money-purchase contributions at the MPAA ({@see contributionHeadroom}).
                    $this->triggerFlexibleAccess($state, $person->id, $pot);
                    $remaining -= $net;
                    $funded += $net;
                    $extraTax += $taxDelta;
                    $fromPension += $gross;
                    $fromPensionTaxFree += $taxFree;
                    $alreadyTaxable += $taxablePart;
                }
                unset($pot);
                $drawnTaxable[$person->id] = $alreadyTaxable;
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
            // Remaining pension - last resort. On Guarantee Credit this is the ONLY pension step:
            // capital comes first precisely so the credit is not clawed back pound for pound.
            // What the taxable part of this draw does to the award is settled by the caller, which
            // re-assesses the means test on it and funds the year again until the two agree
            // ({@see projectYear}, board card 0077). $onGuaranteeCredit is therefore read as at
            // the pass being run: once the claw-back has taken the whole award, the order stops
            // avoiding the pension, because there is no longer a credit to protect.
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

        return ['funded' => $funded, 'extraTax' => $extraTax, 'fromPension' => $fromPension, 'fromPensionTaxFree' => $fromPensionTaxFree, 'fromAssets' => $fromAssets, 'realisedGain' => $realisedGain];
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
        // Selling nothing is a no-op, including from an empty holding: every caller already skips
        // a zero take, and answering it costs nothing.
        if ($take === 0) {
            return [0, 0];
        }

        // Anything else is arithmetic that cannot be done. Being public and static, this is
        // reachable with anything, and it divided by the balance with nothing checking it: an
        // empty holding raised a bare DivisionByZeroError naming neither caller nor holding, and a
        // take beyond the balance reported a gain on money that was not there. A basis ABOVE the
        // balance is NOT in here, because that is a holding at a loss, and a real one.
        if ($take < 0 || $balance <= 0 || $take > $balance) {
            throw new InvalidArgumentException(
                "Cannot dispose of {$take} pence from a GIA holding worth {$balance} pence."
            );
        }

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
     * Gross pension withdrawal whose after-tax value meets $netNeeded, given the person's
     * existing income IN FULL, capped at $maxGross. Iterates to convergence (income tax is
     * piecewise linear, so this is exact within a few rounds).
     */
    private function grossUpPension(int $netNeeded, TaxableIncome $existing, int $maxGross, float $thresholdFactor = 1.0): int
    {
        $gross = $netNeeded;
        for ($i = 0; $i < 8; $i++) {
            $tax = $this->marginalTax($existing, $gross, $thresholdFactor);
            $next = $netNeeded + $tax;
            if (abs($next - $gross) <= 1) {
                $gross = $next;
                break;
            }
            $gross = $next;
        }

        return min($gross, $maxGross);
    }

    /**
     * The tax an extra $extra of NON-SAVINGS income (a pension withdrawal) costs a person who
     * already has $existing. It takes the whole income, not the non-savings part of it, because
     * savings and dividends sit ABOVE non-savings in the band stack: the extra pound pushes them
     * across band boundaries and shrinks the Personal Savings Allowance, and none of that cost
     * is visible from the non-savings leg alone. Board card 0037; before it the withdrawal was
     * priced as though the person held no savings and no shares, and the household was left
     * holding tax it would really have paid.
     *
     * Because the charge is a difference of two FULL-income computations, and each draw starts
     * from where the last one left off, the year's increments telescope exactly onto one
     * recomputation from the final income — the reconciliation DrawdownMarginalTaxTest pins.
     */
    private function marginalTax(TaxableIncome $existing, int $extra, float $thresholdFactor = 1.0): int
    {
        $base = $this->indexedTotalPence($existing, $thresholdFactor);
        $with = $this->indexedTotalPence(new TaxableIncome(
            Money::fromPence($existing->nonSavings->pence + $extra),
            $existing->savings,
            $existing->dividends,
        ), $thresholdFactor);

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

    /**
     * The documented one-off costs falling in this year, each with its label, in nominal pence.
     * Returned as a labelled list rather than a total so an unfunded lump can be NAMED in a
     * warning — a reader cannot act on "£125,000 of spending went unmet" without knowing which
     * cost it was.
     *
     * @param  array<string, int>  $ages
     * @return list<array{label: string, amount: int}>
     */
    private function oneOffCostsNominal(Household $household, array $ages, float $cumInflation, bool $homeSold): array
    {
        // v1 limitation (flagged): a one-off cost has an `atAge` but no `personId`, so it fires on
        // the FIRST-declared person's age only. A cost meant to land at the second person's age
        // cannot trigger, and (since $ages carries dead persons too) the reference age keeps
        // advancing after that person dies. Add a per-cost personId to lift this.
        $referenceId = array_key_first($ages);
        $referenceAge = $ages[$referenceId] ?? null;
        // A lump marked `while_owning_home` is a liability of OWNING the current home (a Section 20
        // major-works demand), so it follows the same rule the service charge does: once the home
        // is gone the bill belongs to whoever bought it. The year-0 sell variants drop these in
        // withoutPropertyCosts; a home sold DURING the projection is caught here.
        $ownsHome = ! $homeSold && $household->primaryResidence !== null;

        $due = [];
        foreach ($household->expenseProfile->oneOffCosts as $cost) {
            if (! $ownsHome && ($cost['condition'] ?? null) === 'while_owning_home') {
                continue;
            }
            if ($referenceAge !== null && ($cost['atAge'] ?? null) === $referenceAge) {
                $due[] = [
                    'label' => $cost['label'] ?? 'One-off cost',
                    'amount' => (int) round($cost['amount']->pence * $cumInflation),
                ];
            }
        }

        return $due;
    }

    /**
     * One warning per one-off capital cost this year could not fund, naming the cost and the
     * amount left unfunded. The shortfall is charged against the costs in REVERSE declaration
     * order (the last lump added is the first to go unfunded), so the attribution is deterministic
     * rather than arbitrary. Empty when everything was funded.
     *
     * @param  list<array{label: string, amount: int}>  $oneOffs
     * @param  callable(int): Money  $m  nominal pence -> the reported Money (real, or the nominal twin)
     * @return list<Warning>
     */
    private function unfundedOneOffWarnings(array $oneOffs, int $unmetOneOffNominal, callable $m): array
    {
        $warnings = [];
        foreach (array_reverse($oneOffs) as $cost) {
            if ($unmetOneOffNominal <= 0) {
                break;
            }
            $unfunded = min($unmetOneOffNominal, $cost['amount']);
            $unmetOneOffNominal -= $unfunded;
            if ($unfunded <= 0) {
                continue;
            }
            $warnings[] = new Warning(
                WarningCode::UNFUNDED_ONE_OFF_COST,
                "{$cost['label']}: ".$m($unfunded)->format().' of this one-off cost has nothing to fund it '
                .'in the year it falls, so the plan is charged for money it does not have. Your ordinary '
                .'year-to-year spending is judged separately and is not counted short because of it.',
            );
        }

        return $warnings;
    }

    /**
     * The tenancy is not only a question of whether the money lasts: it has to be GRANTED. A
     * letting agent's standard reference asks for gross annual income of at least
     * {@see Tenancy::REFERENCING_INCOME_MULTIPLE} times the monthly rent, and it is an INCOME
     * test — the proceeds of the sale the plan has just banked count for nothing towards it. So
     * a household with a large pot and a small pension hits a wall the money-lasts projection
     * cannot see, and until board card 0031 nothing said so.
     *
     * Raised on every year the household's income falls short, not just the first, because rent
     * rises and income does not always keep up: a tenancy granted at 68 can be refused at 78, and
     * a renewal is a fresh reference. The message states both normal ways round it and the money
     * each costs, because a flag that only says "no" leaves the reader nowhere.
     *
     * @param  callable(int): Money  $m  nominal pence -> the reported Money (real, or the nominal twin)
     * @return list<Warning>
     */
    private function rentReferencingWarnings(int $rentChargedNominal, int $grossIncomeNominal, callable $m): array
    {
        if ($rentChargedNominal <= 0) {
            return [];
        }

        $rent = $m($rentChargedNominal);
        $income = $m($grossIncomeNominal);
        if (Tenancy::referencePasses($rent, $income)) {
            return [];
        }

        $monthly = Tenancy::monthlyRent($rent);

        return [new Warning(
            WarningCode::RENT_REFERENCING_FAILED,
            "Renting here needs a landlord to say yes, and on this year's income one normally would not. "
            ."Your income is {$income->format()}. A letting agent's standard reference asks for gross annual "
            .'income of at least '.Tenancy::REFERENCING_INCOME_MULTIPLE.' times the monthly rent — the rent is '
            ."{$monthly->format()} a month, so the bar is ".Tenancy::referencingIncomeRequired($rent)->format()
            .' a year. It is an INCOME test: the money from the sale does not count towards it, however large it is. '
            .'The two usual ways round a failed reference both cost money. One is a UK homeowner guarantor, '
            .'referenced at '.Tenancy::GUARANTOR_INCOME_MULTIPLE.' times the monthly rent against their own income ('
            .Tenancy::guarantorIncomeRequired($rent)->format().' a year) — and a household that has just sold no '
            .'longer has a homeowner in it. The other is rent paid in advance, normally '
            .Tenancy::ADVANCE_MONTHS_MIN.' to '.Tenancy::ADVANCE_MONTHS_MAX.' months of it: '
            .Tenancy::rentInAdvance($rent, Tenancy::ADVANCE_MONTHS_MIN)->format().' to '
            .Tenancy::rentInAdvance($rent, Tenancy::ADVANCE_MONTHS_MAX)->format().' of capital locked up and asked '
            .'for again at every renewal, so it is not invested and not earning while the tenancy runs.',
        )];
    }

    /**
     * What starting the tenancy costs before the keys change hands, stated once, in the year the
     * charge falls. The deposit is charged as a real cost; the first month's rent is NOT charged
     * again on top, because a monthly-in-advance tenancy makes twelve payments in its first year
     * and the rent line already charges twelve. The reader still needs the day-one total, so it
     * is named here — the two figures being invisible was the point of board card 0031.
     *
     * @param  list<array{label: string, amount: int}>  $oneOffs
     * @param  callable(int): Money  $m  nominal pence -> the reported Money (real, or the nominal twin)
     * @return list<Warning>
     */
    private function tenancyUpFrontWarnings(array $oneOffs, int $rentChargedNominal, callable $m): array
    {
        if ($rentChargedNominal <= 0) {
            return [];
        }

        foreach ($oneOffs as $cost) {
            if ($cost['label'] !== Tenancy::UP_FRONT_LABEL) {
                continue;
            }
            $deposit = $m($cost['amount']);
            $monthly = Tenancy::monthlyRent($m($rentChargedNominal));

            return [new Warning(
                WarningCode::TENANCY_UP_FRONT_COST,
                'Starting a tenancy costs money before you get the keys: a deposit of '
                .$deposit->format().' ('.Tenancy::DEPOSIT_WEEKS.' weeks\' rent, the most a landlord may hold '
                .'under the Tenant Fees Act 2019) plus the first month\'s rent of '.$monthly->format().' in '
                .'advance — '.$deposit->plus($monthly)->format().' you have to produce on day one. The deposit '
                .'is charged here as a cost of the plan: it is held for as long as you rent, re-lodged every '
                .'time you move, and only what is left of it comes back at the end. The first month is not '
                .'charged again on top, because a year of monthly-in-advance rent is twelve payments and this '
                .'year\'s rent already charges twelve.',
            )];
        }

        return [];
    }

    /**
     * Board card 0049: one warning, on any year the plan moves a large sum, that the money can be
     * treated as if the household still held it — the notional capital rule for means-tested
     * benefits, the deliberate deprivation test for care charging. Neither is a calculation this
     * engine can do (both turn on motive and on what was foreseeable), so the rule this applies is
     * only "a large sum moved here"; the copy itself has one home, {@see Deprivation}.
     *
     * The events are the ones the model already knows about: a pension lump sum or withdrawal,
     * capital received, a one-off capital cost (which is how a gift out is entered — modelling
     * gifts properly is card 0059), and a forced home sale. A YEAR-0 sell variant sells before the
     * projection starts ({@see HousingComparison::withHousing}
     * hands this projector a household that already holds the proceeds), so that sale is invisible
     * here and is warned about by the presenter instead.
     *
     * "Large" is the £16,000 capital limit that already ends Housing Benefit and Council Tax
     * Support, read from the config that owns it and passed in uprated to this year's prices so the
     * real year and its nominal twin trip on exactly the same moves. A move smaller than that
     * cannot end an award on its own, and a warning on every plan is a warning nobody reads.
     *
     * @param  list<array{label: string, amount: int}>  $oneOffs
     * @param  array<string, int>  $src  this year's income by source, nominal pence
     * @param  int  $thresholdNominal  the capital limit at this year's prices
     * @param  callable(int): Money  $m  nominal pence -> the reported Money (real, or the nominal twin)
     * @return list<Warning>
     */
    private function deprivationWarnings(array $oneOffs, array $src, bool $soldThisYear, int $thresholdNominal, callable $m): array
    {
        $events = [];

        $fromPension = ($src['pension_lump_sum'] ?? 0) + ($src['pension_drawdown'] ?? 0);
        if ($fromPension >= $thresholdNominal) {
            $events[] = 'taking '.$m($fromPension)->format().' out of a pension';
        }
        if (($src['capital_receipt'] ?? 0) >= $thresholdNominal) {
            $events[] = 'receiving '.$m($src['capital_receipt'])->format().' of capital';
        }
        foreach ($oneOffs as $cost) {
            if ($cost['amount'] >= $thresholdNominal) {
                $events[] = $cost['label'].' of '.$m($cost['amount'])->format();
            }
        }
        if ($soldThisYear) {
            $events[] = 'selling your home';
        }

        return $events === [] ? [] : [Deprivation::warning($events, $m($thresholdNominal))];
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
     * Record that $pid has flexibly accessed a pension, which permanently caps their later
     * money-purchase contributions at the MPAA ({@see contributionHeadroom}). THE one place the
     * trigger is set, so the planned route and the two ad-hoc draw closures cannot apply the rule
     * differently — and so the one pot that must NOT set it is excluded once rather than three times.
     *
     * An INHERITED pot does not trigger it. Beneficiary drawdown is not a member trigger event: the
     * heir did not flexibly access a pension of their own. Without this a still-working survivor who
     * drew £10,000 of an inherited pot lost £50,000 of their own annual allowance for the rest of the
     * plan, silently (blocked contributions stay in pay), and at any age — an inherited pot carries
     * access age 0, so it bit below 55 too. Pinned by
     * PathProjectorTest::test_drawing_an_inherited_pot_does_not_cap_the_heirs_own_contributions.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $pot
     */
    private function triggerFlexibleAccess(array &$state, string $pid, array $pot): void
    {
        if ($pot['inherited'] ?? false) {
            return;
        }

        $state['mpaaTriggered'][$pid] = true;
    }

    /**
     * The money-purchase annual allowance that applies to this member for the year, in pence:
     * the ordinary annual allowance (£60,000), or the Money Purchase Annual Allowance (£10,000)
     * once they have flexibly accessed a pension — a UFPLS or drawdown income, planned or drawn
     * to fund a shortfall. The MPAA is the rule that stops a plan drawing a pot down in the free
     * bands and recycling the cash straight back in; the annual allowance is the ordinary ceiling
     * that binds before any of that happens.
     *
     * Both measure the EMPLOYER's contribution as well as the member's, because the statutory
     * allowance is measured on total pension input, not on what the household paid.
     *
     * The allowance is a BILL, not a wall: input above it is paid in and charged
     * ({@see annualAllowanceCharges}, board card 0073). It is settled once, at the end of the
     * year, so which of the two figures applies turns on the trigger DATE rather than on which
     * of the three contribution routes the money took — the employer, net-pay and surplus routes
     * used to get three different answers in the trigger year, purely as an artefact of the year
     * order. Pinned by PathProjectorTest::test_the_mpaa_binds_in_the_year_of_the_trigger.
     *
     * Still absent, both flagged and both outside card 0073: carry-forward of unused allowance
     * from the previous three years, and the high-income taper (which needs adjusted and threshold
     * income, figures this year's own contributions move). Leaving both out is the cautious side
     * of the rule for carry-forward and the generous side for the taper. Both allowances are the
     * frozen statutory figures, not indexed, because nothing has indexed them.
     *
     * @param  array<string, mixed>  $state
     */
    private function applicableAllowance(array $state, string $pid): int
    {
        $params = $this->config->pension;

        return ($state['mpaaTriggered'][$pid] ?? false)
            ? $params->moneyPurchaseAnnualAllowance->pence
            : $params->annualAllowance->pence;
    }

    /**
     * The annual-allowance charge each living member owes on this year's pension input, in nominal
     * pence, keyed by person id — empty where nobody went over.
     *
     * Board card 0073. The allowance used to be a hard cap: a contribution above it simply never
     * reached the pot, so an overpayment vanished instead of appearing as tax, and the plan showed
     * a household that had neither the money nor the pension. In life the contribution IS paid and
     * the excess is charged at the member's marginal rate, which is what takes back the relief it
     * received on the way in.
     *
     * Settled here, after every contribution route AND after the withdrawals that set the MPAA
     * trigger, so the allowance measured against is the one in force at the end of the year.
     * {@see AnnualAllowanceCalculator} owns which allowance applies to what, so the rule has one
     * home; the charge itself is {@see marginalTax} on the excess, the same income-tax pass every
     * other figure in this year uses. Carry-forward and the taper are passed as nil — neither is
     * modelled ({@see applicableAllowance}) — so the calculator is asked only the question this
     * projector can answer, and it is asked at all only for a member who actually paid something
     * in, which is rare enough to keep the hot loop cheap.
     *
     * $taxablePerPerson is the year's non-savings income BEFORE any ad-hoc draw made to fund a
     * shortfall, so a member whose shortfall draw pushed them into a higher band is charged at the
     * band they were in without it. That understates the charge in that one case; the alternative
     * is to price the charge before the draw exists, which cannot see the MPAA trigger the draw
     * itself sets.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $taxablePerPerson
     * @param  array<string, int>  $savingsPerPerson
     * @param  array<string, int>  $dividendsPerPerson
     * @return array<string, int>
     */
    /**
     * What the pension money still in the pots at the end of this year would cost in income tax if
     * it were drawn — the difference between a pot's face value and what it is worth to spend.
     * Board card 0076: spendable wealth counted the pot at face value, which is what the
     * safety-buffer warning was measured against and what the buy / rent / stay-put plans were
     * ranked on, so the warning fired late and the plan holding most of its wealth inside a pension
     * won on a figure it had not earned.
     *
     * The tax-free part comes off FIRST and is never netted: a quarter of the uncrystallised money,
     * capped by what is left of that member's Lump Sum Allowance, read through the same
     * {@see lsaHeadroom} and {@see ufplsSplit} the planned and ad-hoc draw routes use, so the split
     * has one home and a pot already crystallised (or inherited) gets no second quarter here
     * either. The running allowance ledger starts from the member's own $state['lsaUsed'], so cash
     * they have already taken has already spent it.
     *
     * The balance is charged at the member's PROJECTED MARGINAL RATE — the rate on the next pound
     * of pension income at this year's income, measured over {@see MARGINAL_RATE_PROBE_PENCE} and
     * applied flat. It is deliberately NOT the tax on encashing the whole pot in one year: nobody
     * draws a pot that way, and pricing it so would understate the pot by tens of thousands. The
     * ceiling of that choice is the reverse case — a member whose projected income is inside the
     * personal allowance nets nothing here, though drawing a large pot would certainly be taxed —
     * and it is why the rate is disclosed beside the figure rather than applied silently.
     *
     * Reported, never spent: no funding decision reads it, so nothing about the projection moves.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $taxablePerPerson
     * @param  array<string, int>  $savingsPerPerson
     * @param  array<string, int>  $dividendsPerPerson
     * @return array{taxable: int, tax: int} both in nominal pence
     */
    private function pensionTaxIfDrawn(array $state, array $taxablePerPerson, array $savingsPerPerson, array $dividendsPerPerson, float $thresholdFactor): array
    {
        $lsa = $this->config->pension->lumpSumAllowance->pence;
        $pclsRate = $this->config->pension->pclsRate->asFraction();
        $taxableTotal = 0;
        $tax = 0;

        foreach ($state['pots'] as $pid => $pots) {
            $lsaUsed = $state['lsaUsed'][$pid] ?? 0;
            $taxable = 0;
            foreach ($pots as $pot) {
                if ($pot['value'] <= 0) {
                    continue;
                }
                [$taxFree, $charged] = self::ufplsSplit(
                    $pot['value'],
                    self::lsaHeadroom($lsa, $lsaUsed, $pot),
                    $pclsRate,
                    $pot['crystallised'] ?? 0,
                );
                $lsaUsed += $taxFree;
                $taxable += $charged;
            }

            $taxableTotal += $taxable;
            if ($taxable <= 0) {
                continue;
            }

            $rate = $this->marginalTax(new TaxableIncome(
                Money::fromPence($taxablePerPerson[$pid] ?? 0),
                Money::fromPence($savingsPerPerson[$pid] ?? 0),
                Money::fromPence($dividendsPerPerson[$pid] ?? 0),
            ), self::MARGINAL_RATE_PROBE_PENCE, $thresholdFactor);

            $tax += (int) round($taxable * $rate / self::MARGINAL_RATE_PROBE_PENCE);
        }

        return ['taxable' => $taxableTotal, 'tax' => $tax];
    }

    private function annualAllowanceCharges(array $state, array $alive, array $taxablePerPerson, array $savingsPerPerson, array $dividendsPerPerson, float $thresholdFactor): array
    {
        $charges = [];
        foreach ($state['mpContributed'] as $pid => $paidIn) {
            if ($paidIn <= 0 || ! ($alive[$pid] ?? false)) {
                continue;
            }

            $excess = $this->annualAllowance->assess(
                Money::fromPence($paidIn),
                $state['mpaaTriggered'][$pid] ?? false,
                Money::zero(),
                Money::zero(),
                Money::zero(),
            )->excessContributions->pence;

            if ($excess <= 0) {
                continue;
            }

            $charges[$pid] = $this->marginalTax(new TaxableIncome(
                Money::fromPence($taxablePerPerson[$pid] ?? 0),
                Money::fromPence($savingsPerPerson[$pid] ?? 0),
                Money::fromPence($dividendsPerPerson[$pid] ?? 0),
            ), $excess, $thresholdFactor);
        }

        return $charges;
    }

    /**
     * The allowance the year was measured against and the charge going over it cost, said out
     * loud — one warning per charged member.
     *
     * Neither figure is one the reader entered: the allowance is statutory (and which of the two
     * applies turns on a trigger they may not know they pulled), and the charge is real tax the
     * plan pays. The house rule is that the model never uses a figure the reader cannot see, so
     * both are stated with their values, read from the constants and the arithmetic that produced
     * them. The app surfaces this among its assumed-figure notes. (Named in prose, not as a doc
     * link: an engine file must not carry a fully-qualified app class, which Pint has previously
     * promoted into a real import.)
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $charges
     * @return list<Warning>
     */
    private function allowanceChargeWarnings(array $state, array $charges, callable $m): array
    {
        $out = [];
        foreach ($charges as $pid => $charge) {
            $allowance = Money::fromPence($this->applicableAllowance($state, $pid));
            $out[] = new Warning(
                WarningCode::ANNUAL_ALLOWANCE_EXCEEDED,
                'Pension input of '.$m($state['mpContributed'][$pid])->format().' this year — everything paid in, '
                .'the employer\'s share as well as the member\'s — is above the '.$allowance->format()
                .' allowance that applies'
                .(($state['mpaaTriggered'][$pid] ?? false)
                    ? ' (the Money Purchase Annual Allowance, because money has been taken flexibly out of a pension)'
                    : '')
                .'. The contribution is not refused: it is paid in, and an annual allowance charge of '
                .$m($charge)->format().' falls on the excess at the marginal rate, which takes back the tax '
                .'relief the excess received.',
            );
        }

        return $out;
    }

    /**
     * The year the MPAA first applies, said out loud — at most one warning, in that year only.
     *
     * The cap is a figure the reader never entered and can move their result by thousands of
     * pounds: from the trigger on, a contribution in their plan above the allowance is charged
     * ({@see applicableAllowance}). Until this existed the only place the MPAA was ever stated
     * was the app's lump-sum tax-shock panel, which needs a PLANNED withdrawal instruction to say
     * anything at all — yet an ad-hoc draw to meet a shortfall triggers the cap under every draw
     * order. So on an ordinary plan the cap applied and no screen mentioned it.
     *
     * Not emitted for a member with no money-purchase contributions in their plan: an allowance on
     * what may be paid in changes nothing for someone paying nothing in, and the disclosure list is
     * only useful while everything on it bites.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $before  who had already triggered it when the year opened
     * @return list<Warning>
     */
    private function mpaaWarnings(array $state, array $before): array
    {
        foreach ($state['mpaaTriggered'] as $pid => $triggered) {
            if (! $triggered || ($before[$pid] ?? false)) {
                continue;
            }
            foreach ($state['pots'][$pid] ?? [] as $pot) {
                if (($pot['contribution'] ?? 0) > 0 || ($pot['employerContribution'] ?? 0) > 0) {
                    // One per year, not one per member: the sentence names no one, so a couple
                    // triggering together would only say the same thing twice.
                    return [new Warning(
                        WarningCode::MPAA_TRIGGERED,
                        'Money is taken flexibly out of a pension in this plan, so from that year on no more than '
                        .$this->config->pension->moneyPurchaseAnnualAllowance->format()
                        .' a year can be paid into that person\'s money-purchase pensions without a tax charge — the '
                        .'Money Purchase Annual Allowance, which replaces the ordinary annual allowance for the rest '
                        .'of the plan, and which applies from the day the money is taken, not from the following year. '
                        .'Contributions above it are still paid in: what they cost is an annual allowance charge on '
                        .'the excess, at the person\'s marginal rate.',
                    )];
                }
            }
        }

        return [];
    }

    /**
     * Pay $amount into one of this member's money-purchase pots and return what went in. THE one
     * place a DC pot is credited with a contribution, so the year's running total of pension input
     * — which the annual allowance is measured on ({@see annualAllowanceCharges}) — has one home.
     *
     * Nothing is refused here. The annual allowance is a charge on the excess, settled once at the
     * end of the year; the limits that DO bind a contribution at source are the member's pay (a
     * net-pay contribution cannot exceed it), the household's surplus, and the basic amount on the
     * non-earner relief route, each applied by its own caller.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $pot
     */
    private function payIntoPot(array &$state, string $pid, array &$pot, int $amount): int
    {
        $give = max(0, $amount);
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
            // What the allowance blocks is never given up, so it stays in pay and is taxed there:
            // the caller subtracts only what actually reached the pot.
            $paid += $this->payIntoPot($state, $pid, $pot, max(0, min($wanted, $earnings - $paid)));
        }
        unset($pot);

        return $paid;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, bool>  $alive
     * @param  array<string, int>  $ages
     */
    private function applyContributions(Household $household, array &$state, array $alive, array $ages, float $spendFactor, int $surplus): int
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
        $pension = $this->config->pension;
        $basicRate = $this->config->incomeTax->basicRate;
        foreach ($household->persons as $person) {
            if (! ($alive[$person->id] ?? false)) {
                continue;
            }
            $nonEarnerPaid = 0; // gross already relieved on the basic-amount route this year
            foreach ($state['pots'][$person->id] as &$pot) {
                $method = $pot['reliefMethod'] ?? null;
                if ($method === PensionReliefMethod::NetPay) {
                    continue;
                }

                // The non-earner "basic amount" route: the household pays the NET figure out of
                // surplus and the provider adds basic-rate relief, so a bigger sum lands in the
                // pot than left the bank. Relief ends at 75, and the GROSS is capped at the basic
                // amount per person per year, and every member has that floor whatever they earn, so
                // it needs no earnings test, but relief on more than it needs net pay.
                if ($method === PensionReliefMethod::NonEarner) {
                    if (($ages[$person->id] ?? 0) >= $pension->reliefMaximumAge) {
                        continue;
                    }
                    $grossHeadroom = max(0, $pension->nonEarnerReliefLimit->pence - $nonEarnerPaid);
                    // Net cap = gross cap less the relief the provider reclaims on it.
                    $netCap = Money::fromPence($grossHeadroom)->minus(Money::fromPence($grossHeadroom)->applyRate($basicRate))->pence;
                    $net = $take($pot['contribution'] ?? 0, $netCap);
                    if ($net <= 0) {
                        continue;
                    }
                    // Gross the net payment back up at the basic rate: £2,880 net buys £3,600
                    // gross, i.e. net / (1 - 20%). Derived from the rate the tax pass uses, so
                    // the two cannot drift.
                    $gross = min($grossHeadroom, (int) round($net * 10_000 / (10_000 - $basicRate->basisPoints)));
                    $nonEarnerPaid += $this->payIntoPot($state, $person->id, $pot, $gross);

                    continue;
                }

                // Bounded only by the surplus there is to pay it from. The annual allowance does
                // not stop it: going over is a charge settled at the end of the year, not a refusal
                // ({@see annualAllowanceCharges}).
                $this->payIntoPot($state, $person->id, $pot, $take($pot['contribution'] ?? 0));
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
                $headroom = max(0, $isaAllowance - ($state['isaSubscribed'][$owner] ?? 0));
                $intoIsa = min($added, $headroom);
                $state['isaSubscribed'][$owner] = ($state['isaSubscribed'][$owner] ?? 0) + $intoIsa;
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

    /**
     * "Bed and ISA": move money the household already holds in a taxable General Investment
     * Account into their ISA, up to each person's UNUSED ISA subscription allowance for the year.
     * Nothing is spent and nothing is earned: the same pounds simply stop being taxable, so from
     * next year their growth and their dividends are sheltered.
     *
     * Until this existed the engine ENFORCED the ISA allowance but never USED it, which
     * understated every plan that sells a home and invests the proceeds: those proceeds land in a
     * GIA, and a real household would move £20,000 each into an ISA every year until the GIA was
     * empty. See DATA-MODEL "Known divergences".
     *
     * It is a disposal, so it realises the pro-rata gain and consumes the matching cost basis
     * exactly as a sale to fund spending does. The transfer is sized so that gain stays inside
     * what is LEFT of the person's CGT annual exempt amount after the year's other disposals,
     * which is the discipline a real bed-and-ISA follows (you move what you can shelter for free)
     * and means the step never adds a tax bill the projection would then have to fund. Where the
     * exempt amount is already spent, nothing moves this year.
     *
     * @param  array<string, bool>  $alive
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $realisedGain  gains this year already counted against the AEA
     * @return int the total moved into ISAs (nominal pence)
     */
    private function bedAndIsa(Household $household, array &$state, array $alive, array $realisedGain): int
    {
        $isaAllowance = $this->config->isa->overallAllowance->pence;
        $aea = $this->config->cgt->annualExemptAmount->pence;
        $moved = 0;

        foreach ($household->persons as $person) {
            $pid = $person->id;
            if (! ($alive[$pid] ?? false)) {
                continue;
            }
            $bal = $state['gia'][$pid] ?? 0;
            $headroom = max(0, $isaAllowance - ($state['isaSubscribed'][$pid] ?? 0));
            if ($bal <= 0 || $headroom <= 0) {
                continue;
            }
            $basis = $state['giaBasis'][$pid] ?? 0;
            // The largest disposal whose realised gain stays inside the remaining exempt amount;
            // a holding with no gain can move freely. Mirrors drawGiaToAea deliberately: one
            // definition of "how much can be sold without a CGT bill".
            $gainRoom = max(0, $aea - ($realisedGain[$pid] ?? 0));
            $maxByGain = $bal > $basis ? (int) floor($gainRoom * $bal / ($bal - $basis)) : $bal;
            $take = min($bal, $headroom, $maxByGain);
            if ($take <= 0) {
                continue;
            }

            [, $basisConsumed] = self::disposeGiaSlice($bal, $basis, $take);
            $state['gia'][$pid] -= $take;
            $state['giaBasis'][$pid] -= $basisConsumed;
            $state['isa'][$pid] += $take;
            $state['isaSubscribed'][$pid] = ($state['isaSubscribed'][$pid] ?? 0) + $take;
            $moved += $take;
        }

        return $moved;
    }

    private function firstLiving(Household $household, array $alive): ?string
    {
        return $this->livingIds($household, $alive)[0] ?? null;
    }

    /**
     * Who this year's banked surplus belongs to, in pence (board card 0040).
     *
     * The surplus is what the household's income left over after its spending, so it is banked in
     * PROPORTION to the net income each living member produced: their taxable income after tax and
     * NI, their investment income, their tax-free streams and pension cash, and a capital receipt
     * in their own name. That is the "where it is attributable" half of the criterion.
     *
     * The other half is money nobody generated. Pension Credit is a household award and the
     * buy-to-let finance-cost reducer is modelled household-wide, so neither carries a person's
     * name; on a year whose whole surplus is of that kind, no weight is positive and
     * {@see PenceSplit::byWeight} shares it evenly. A dead member is excluded, so their share
     * passes to the survivors rather than accumulating in a name nobody can spend.
     *
     * Spending is NOT netted off person by person: the engine holds one household expense profile,
     * so there is no honest per-person share of it to subtract. Proportion of net income is the
     * approximation, and it is flagged here rather than hidden: a couple where one earns
     * everything and the other pays all the bills would in life bank differently from this.
     *
     * @param  array<string, int>  $netPerPerson
     * @param  array<string, bool>  $alive
     * @return array<string, int>
     */
    private function attributeSurplus(int $surplus, array $netPerPerson, array $alive): array
    {
        $weights = [];
        foreach ($netPerPerson as $personId => $net) {
            if ($alive[$personId] ?? false) {
                $weights[$personId] = $net;
            }
        }

        return PenceSplit::byWeight($surplus, $weights);
    }

    /**
     * Everyone still alive, in declaration order. Money that belongs to the household rather than
     * to one member is divided over this ({@see PenceSplit}), so it cannot land on whoever was
     * typed first.
     *
     * @param  array<string, bool>  $alive
     * @return list<string>
     */
    private function livingIds(Household $household, array $alive): array
    {
        $ids = [];
        foreach ($household->persons as $person) {
            if ($alive[$person->id]) {
                $ids[] = $person->id;
            }
        }

        return $ids;
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
                $wasWorth = $pot['value'];
                $pot['value'] = $charged($potGrown);
                // A drawdown fund grows with the rest of the pot, so the crystallised SHARE has to
                // hold. Growing only the total would quietly turn growth into fresh uncrystallised
                // money and hand it a second tax-free quarter ({@see ufplsSplit}).
                if (($pot['crystallised'] ?? 0) > 0 && $wasWorth > 0) {
                    $pot['crystallised'] = min($pot['value'], (int) round($pot['crystallised'] * $pot['value'] / $wasWorth));
                }
            }
            unset($pot);

            $growth += $grown - $before;
        }

        // The home's own growth. A per-property override sets the MEAN it grows at; the driver
        // keeps drawing the year's variation around that mean, at single-property width rather
        // than index width ({@see PathDraws::propertyGrowthReal}). It used to REPLACE the draw,
        // which made every overridden home a certainty, and an override is how somebody says a
        // home is unusual, so the least predictable homes were the ones being flattened.
        $propertyReal = $draws->propertyGrowthReal($yearIndex, $state['propertyGrowthReal']);
        $propertyNominal = (1.0 + $propertyReal) * (1.0 + $infl) - 1.0;
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

        // The Support for Mortgage Interest charge rolls up too: it is a loan, and what DWP has
        // already paid out accrues until the home is sold or the last owner dies. It is rolled at
        // the same standard rate rather than at a second rate of its own — DWP sets the loan's
        // interest from gilt yields, which is a figure this engine does not hold, and inventing
        // one to sit beside a sourced one is worse than reusing it. Uncapped, unlike the mortgage
        // above: the debt is real even where the security cannot bear it, and the write-off is
        // applied where it belongs, in the zero floor on home equity and on the estate.
        if ($state['smiBalance'] > 0) {
            $state['smiBalance'] = (int) round($state['smiBalance'] * (1.0 + SupportForMortgageInterest::standardRate()->asFraction()));
        }

        // A deferred payment agreement rolls up the same way, at the statutory maximum rate the
        // regulations set ({@see DeferredPaymentAgreement}). Uncapped for the same reason as the
        // SMI charge above: the debt is real even where the security cannot bear it, and the
        // write-off belongs in the zero floor on home equity and on the estate.
        if ($state['deferredCareBalance'] > 0) {
            $state['deferredCareBalance'] = (int) round($state['deferredCareBalance'] * (1.0 + DeferredPaymentAgreement::interestRate()->asFraction()));
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
        $this->escalateDbPensions($state, $infl, $yearIndex);
        // The State Pension's uprating, on the basis the reader chose ({@see StatePensionUprating}).
        // Bump number n carries the factor into year n, the same boundary escalateDbPensions uses,
        // so "the lock ends in 2036" means 2036 is the last year the floor lifts the pension into.
        $state['spFactor'] *= 1.0 + $state['spUprating']->increase(
            $infl,
            $state['baseYear'] + $yearIndex + 1,
            $state['spTripleLockUntilYear'],
        );
        $state['spendFactor'] *= (1.0 + $infl);
        $state['rentFactor'] *= (1.0 + $rentNominal);

        return ['growth' => $growth, 'charges' => $charges];
    }

    /**
     * Carry every Defined Benefit scheme's factor into next year, each on ITS OWN basis and in
     * the phase it is actually in. Two rules, not one: a scheme REVALUES while the member is
     * deferred and ESCALATES once the pension is in payment, and the reader chooses each
     * separately. Until board card 0035 this was one household-wide factor pinned to full CPI, so
     * both dropdowns were collected and neither was read.
     *
     * The boundary: bump number n carries the factor into year n, and the pension comes into
     * payment in the year the member reaches normal retirement age. So a bump whose landing year
     * is at or before that age is still deferment (it is what delivers the revalued pension to
     * the payment date), and every later bump is escalation in payment. A member already past
     * normal retirement age in the base year is in payment for every bump, as they should be.
     *
     * @param  array<string, mixed>  $state
     */
    private function escalateDbPensions(array &$state, float $inflation, int $yearIndex): void
    {
        foreach ($state['dbSchemes'] as $key => $scheme) {
            $ageNextYear = $state['baseAge'][$scheme['ownerId']] + $yearIndex + 1;
            $basis = $ageNextYear <= $scheme['normalRetirementAge']
                ? $scheme['revaluationBasis']
                : $scheme['escalationInPayment'];

            $state['dbFactors'][$key] *= 1.0 + $basis->increase($inflation, $scheme['fixedRate']);
        }
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
