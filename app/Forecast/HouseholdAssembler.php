<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Finance\Mapping\Codec;
use DateTimeImmutable;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\CouncilTaxBand;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\DeathInServiceCover;
use RetireForecast\FinanceEngine\Dto\DisabilityAwardRate;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\PensionBeneficiary;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\PensionReliefMethod;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\SpendPath;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * Turns the scenario builder's plain form state into the engine's input DTOs. This
 * is the third consumer of the one canonical shape (engine + storage + UI): the
 * builder collects strings, this assembles them into a {@see Household} and a
 * {@see HousingAction}, and the mappers serialise those for storage.
 *
 * Pounds-and-pence the user types are parsed to exact integer pence here (no float
 * in money), so a value entered, assembled, stored and re-read is lossless. Kept
 * separate from the Livewire component so it is unit-testable and reusable (e.g. the
 * demo preset).
 */
final class HouseholdAssembler
{
    /**
     * @param  array<string, mixed>  $state  the builder's validated form state
     * @return array{household: Household, housingAction: HousingAction}
     */
    public function assemble(array $state): array
    {
        return [
            'household' => $this->household($state),
            'housingAction' => $this->housingAction($state['housing'] ?? []),
        ];
    }

    public function household(array $state): Household
    {
        // A spend line switched off in the builder (included === false) is kept in the form-state
        // so it can be switched back on, but must contribute nothing to the forecast. Drop the
        // excluded lines once here so every downstream total (essential, discretionary, contingent
        // costs, saved self-investment) excludes them uniformly. Absent flag = included (back-compat).
        $state['expenseLines'] = array_values(array_filter(
            $state['expenseLines'] ?? [],
            static fn (array $line): bool => ($line['included'] ?? true) !== false,
        ));

        return new Household(
            name: (string) $state['householdName'],
            region: RegionProfile::from($state['region']),
            persons: array_map($this->person(...), $state['people'] ?? []),
            expenseProfile: $this->expenseProfile($state),
            pensions: array_map($this->pension(...), $state['pensions'] ?? []),
            accounts: $this->accounts($state),
            incomeStreams: array_map($this->incomeStream(...), $state['incomeStreams'] ?? []),
            primaryResidence: ($state['hasProperty'] ?? false)
                ? $this->property($state['property'] ?? [], (int) substr((string) ($state['baseTaxYear'] ?? '2026-27'), 0, 4), $state['people'] ?? [])
                : null,
            // Relationship status drives the IHT treatment on death, the survivor's DB and State
            // Pension rights, and both transferable bands. A blank or absent answer stays NULL here
            // rather than being read as married: the DTO still projects one so an old scenario keeps
            // its figures, but the null is what makes the results page disclose it (card 0054).
            relationshipStatus: RelationshipStatus::tryFrom((string) ($state['relationshipStatus'] ?? '')),
            capitalReceipts: array_map($this->capitalReceipt(...), $state['capitalReceipts'] ?? []),
            // The date of the marriage or civil partnership, which decides which State Pension
            // inheritance rules a survivor falls under. Blank or absent = not given.
            marriageDate: $this->stringOrNull($state['marriageDate'] ?? null),
        );
    }

    public function housingAction(array $h): HousingAction
    {
        return new HousingAction(
            salePrice: $this->moneyRequired($h['salePrice'] ?? null),
            buyPrice: $this->money($h['buyPrice'] ?? null),
            annualRent: $this->money($h['annualRent'] ?? null),
            rentInflationReal: $this->percent($h['rentInflationReal'] ?? null),
            movingCosts: $this->money($h['movingCosts'] ?? null),
            sellingCosts: $this->sellingCosts($h),
            buyMortgageRate: $this->percent($h['buyMortgageRate'] ?? null),
            buyRunningCosts: $this->money($h['buyRunningCosts'] ?? null),
            // May be NEGATIVE: a park home depreciates. percent() must not clamp it.
            buyGrowthOverride: $this->percent($h['buyGrowthReal'] ?? null),
        );
    }

    /**
     * The selling-cost components, each entered on its own basis — a % of the sale price
     * (how agents quote) or a flat £ (how conveyancing quotes), the basis being the value's
     * type. A blank component contributes nothing and is dropped; all-blank yields null, so
     * the engine applies its own default rate (matching the old empty-rate behaviour).
     *
     * Back-compat: a scenario saved before the breakdown existed carries only the old single
     * `sellingCostRate`. It maps to one estate-agent component on that %, preserving the old
     * total exactly (the other components default to nothing); a blank old rate yields null,
     * so the engine default still applies. One home per figure — never both shapes at once.
     *
     * @param  array<string, mixed>  $h  the housing form-state
     * @return list<SellingCostComponent>|null
     */
    private function sellingCosts(array $h): ?array
    {
        if (isset($h['sellingCosts']) && is_array($h['sellingCosts'])) {
            $components = [];
            foreach ($h['sellingCosts'] as $line) {
                $value = (string) ($line['value'] ?? '');
                if (trim($value) === '') {
                    continue; // an empty line costs nothing
                }

                $components[] = new SellingCostComponent(
                    (string) ($line['label'] ?? 'Selling cost'),
                    ($line['basis'] ?? 'percent') === 'fixed'
                        ? Money::fromPence($this->toPence($value))
                        : Percent::fromPercent((float) $value),
                );
            }

            return $components === [] ? null : $components;
        }

        // Back-compat: the old single rate becomes one estate-agent component on that %.
        $rate = $this->percent($h['sellingCostRate'] ?? null);

        return $rate === null ? null : [new SellingCostComponent('Estate agent', $rate)];
    }

    private function person(array $p): Person
    {
        return new Person(
            id: (string) $p['id'],
            dob: $this->date($p['dob']),
            sex: Sex::from($p['sex']),
            employmentStatus: EmploymentStatus::from($p['employmentStatus']),
            grossSalary: $this->money($p['grossSalary'] ?? null),
            salaryGrowth: $this->percent($p['salaryGrowth'] ?? null),
            plannedRetirementAge: $this->intOrNull($p['plannedRetirementAge'] ?? null),
            niCategory: $this->stringOrNull($p['niCategory'] ?? null),
            name: $this->stringOrNull($p['name'] ?? null),
            longevity: $this->longevity($p),
            receivesDisabilityBenefit: (bool) ($p['receivesDisabilityBenefit'] ?? false),
            caresForPartner: (bool) ($p['caresForPartner'] ?? false),
            deathInServiceCover: $this->deathInServiceCover($p),
            // Blank or absent = in payment for the whole projection, which is how every scenario
            // saved before the start age existed behaved.
            disabilityBenefitFromAge: $this->intOrNull($p['disabilityBenefitFromAge'] ?? null),
            // Blank or absent = the qualifying care rate, which is what the disability flag alone
            // has always meant, so a scenario saved before this field existed keeps its answer.
            disabilityAwardRate: DisabilityAwardRate::tryFrom((string) ($p['disabilityAwardRate'] ?? ''))
                ?? DisabilityAwardRate::QualifyingCare,
            // Blank or absent = NO will, the adverse answer. A scenario saved before the question
            // existed was never asked, and a will that does not exist is what intestacy is for.
            hasWill: (bool) ($p['hasWill'] ?? false),
            // Blank or absent = never asked, which the engine treats as a UK long-term resident (an
            // unlimited spouse exemption, what every forecast did before the question existed) and
            // the results page discloses as an assumed figure.
            ukLongTermResident: match ((string) ($p['ukLongTermResident'] ?? '')) {
                'yes' => true,
                'no' => false,
                default => null,
            },
        );
    }

    /**
     * Employer death-in-service (group life) cover, stated as the scheme states it: a multiple of
     * salary or a fixed sum assured. A blank or absent mode is no cover (null) — the adverse
     * assumption, and byte-identical to a projection that never knew about it.
     *
     * A mode with a blank figure is treated as no cover rather than as zero cover, so a
     * half-completed input never silently claims a policy exists; validation asks for the figure.
     *
     * @param  array<string, mixed>  $p
     */
    private function deathInServiceCover(array $p): ?DeathInServiceCover
    {
        $mode = (string) ($p['deathInServiceMode'] ?? '');

        if ($mode === 'multiple') {
            $multiple = $this->floatOrNull($p['deathInServiceMultiple'] ?? null);

            // The DTO holds the multiple as a Percent (integer basis points, the engine's no-floats
            // rule), so "4x salary" is 400%.
            return $multiple === null
                ? null
                : DeathInServiceCover::multipleOfSalary(Percent::fromPercent($multiple * 100));
        }

        if ($mode === 'fixed') {
            $sum = $this->money($p['deathInServiceSum'] ?? null);

            return $sum === null ? null : DeathInServiceCover::fixedSum($sum);
        }

        return null;
    }

    /**
     * The lifespan what-if for a person: "fixed_age" assumes death at a given age,
     * "offset_years" shifts the cohort-table peer death age by ± whole years. "peer" (or
     * an absent/blank field) leaves the cohort-table average untouched (null). This only
     * moves when a death occurs — never any tax or cashflow figure.
     *
     * @param  array<string, mixed>  $p
     */
    private function longevity(array $p): ?LongevityAdjustment
    {
        $value = $p['longevityValue'] ?? '';
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($p['longevityMode'] ?? 'peer') {
            'fixed_age' => LongevityAdjustment::fixedAge((int) $value),
            'offset_years' => LongevityAdjustment::offsetYears((int) $value),
            default => null,
        };
    }

    /**
     * The household's spending. With Phase C1, the **line items are the source**: the
     * essential and discretionary annual totals are the *sum of the lines* (no stored
     * total to drift). Spending self-investment (courses, books — `savedAsAsset` false)
     * is consumption, so it folds into discretionary; saved self-investment is not
     * spend at all (it builds net worth — see {@see accounts()}). A scenario predating
     * line items (none present) falls back to the flat essential/discretionary totals.
     */
    private function expenseProfile(array $state): ExpenseProfile
    {
        $e = $state['expense'] ?? [];
        [$essentialPath, $discretionaryPath] = $this->essentialAndDiscretionary($state);

        // Contingent costs (option b): the portions of spend tied to a condition, summed from
        // the spend lines whose condition (explicit override, else auto-classified by label)
        // is housing- or employment-linked. They are a marked subset of essential/discretionary,
        // so the engine can stop charging them when the home is sold / the household retires.
        $lines = $state['expenseLines'] ?? [];
        $isSpend = fn (array $l): bool => ! (($l['category'] ?? '') === 'self_investment' && ($l['savedAsAsset'] ?? false));
        $propertyCosts = $this->sumLines($lines, fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_owning_home');
        // The part of those home-ownership costs that BUYS UTILITIES (a service charge covering the
        // water and the communal electricity). It is the one part that survives the sale, so it is
        // summed only off the lines that would otherwise take it with them.
        $utilities = $this->sumLines(
            $lines,
            fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_owning_home',
            'utilities',
        );
        $mortgageCosts = $this->sumLines($lines, fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_mortgaged');
        $employmentCosts = $this->sumLines($lines, fn (array $l): bool => $isSpend($l) && $this->lineCondition($l) === 'while_working');

        return new ExpenseProfile(
            essentialAnnualSpend: $essentialPath->startAmount(),
            discretionaryAnnualSpend: $discretionaryPath->startAmount(),
            survivorSpendFactor: $this->percent($e['survivorFactor'] ?? null) ?? Percent::fromPercent(70),
            oneOffCosts: array_map(function (array $c): array {
                $cost = [
                    'atAge' => (int) $c['atAge'],
                    'amount' => $this->moneyRequired($c['amount'] ?? null),
                    'label' => (string) ($c['label'] ?? ''),
                ];

                // A lump the reader tied to owning this home (a Section 20 major-works demand)
                // dies with the home, like the service charge beside it. Carried sparsely: an
                // unmarked one-off is charged always, exactly as before.
                if (($c['condition'] ?? '') === 'while_owning_home') {
                    $cost['condition'] = 'while_owning_home';
                }

                return $cost;
            }, $state['oneOffCosts'] ?? []),
            propertyCosts: $propertyCosts->isPositive() ? $propertyCosts : null,
            employmentCosts: $employmentCosts->isPositive() ? $employmentCosts : null,
            mortgageCosts: $mortgageCosts->isPositive() ? $mortgageCosts : null,
            essentialSpendPath: $essentialPath,
            discretionarySpendPath: $discretionaryPath,
            // Above-CPI growth for the while-owning-home lines (service charge / ground rent /
            // levies) — a leaseholder's "rises faster than inflation" lever (see ExpenseProfile).
            propertyCostsRealGrowth: $this->percent($e['propertyCostsGrowthPct'] ?? null),
            // What the home-ownership costs BUY in utilities, which the household keeps paying for
            // wherever it lives next — so it is carried across a sale rather than deleted with the
            // charge. Sparse: no figure entered means the charge buys none, as before.
            propertyCostsUtilities: $utilities->isPositive() ? $utilities : null,
        );
    }

    /**
     * The condition under which an expense line is charged — the user's explicit override if
     * set (option b's per-line override), else auto-classified by label: housing-linked labels
     * (mortgage, service charge, ground rent) are charged only *while owning* the current home;
     * commuting only *while working*; everything else *always*. Saved self-investment is not
     * spend, so callers exclude it before classifying.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineCondition(array $line): string
    {
        $explicit = $line['condition'] ?? null;
        if (is_string($explicit) && in_array($explicit, ['always', 'while_owning_home', 'while_mortgaged', 'while_working'], true)) {
            return $explicit;
        }

        return self::autoCondition($line);
    }

    /**
     * The condition a line auto-classifies to from its label alone (ignoring any explicit
     * override): a **mortgage** is *while mortgaged* (its payment stops when the mortgage ends,
     * by sale OR redemption); other housing-linked labels (service charge, ground rent, factor
     * fee) are *while owning* the current home (they continue while it is owned, redemption or
     * not); commuting is *while working*; everything else *always*. Public so the builder can
     * show what "Auto" would infer.
     *
     * @param  array<string, mixed>  $line
     */
    public static function autoCondition(array $line): string
    {
        $label = strtolower((string) ($line['label'] ?? ''));
        if (str_contains($label, 'mortgage')) {
            return 'while_mortgaged';
        }
        foreach (['service charge', 'ground rent', 'factor fee'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 'while_owning_home';
            }
        }
        foreach (['commute', 'commuting', 'season ticket'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 'while_working';
            }
        }

        return 'always';
    }

    /**
     * The tier a spend line actually counts in: the reader's own choice, except where the line is
     * cover of the home. **Buildings and contents insurance is essential wherever cover is
     * required** (board card 0033). Buildings cover is a condition of every mortgage, contents
     * cover replaces the things a household cannot do without, and a leaseholder's separate policy
     * sits on top of the buildings cover already inside the service charge. Filed as discretionary
     * it sat outside the essential floor, which flattered the "essentials always met" probability
     * on every scenario.
     *
     * Only a *discretionary* line moves. A reader who filed it as essential already agrees, and
     * self-investment is a different question. The label has to name the home as well as the cover:
     * pet, travel, car and gadget policies are genuinely optional, so promoting every line with
     * "insurance" in it would overstate the floor rather than correct it.
     *
     * PUBLIC because three surfaces have to agree on the answer: the forecast (below), the
     * builder's live totals and the results page's spending breakdown. One rule, one home. A second
     * copy would let a screen and the projection disagree about the same pounds.
     *
     * @param  array<string, mixed>  $line
     */
    public static function tierOf(array $line): string
    {
        $category = (string) ($line['category'] ?? '');
        $label = strtolower((string) ($line['label'] ?? ''));

        if ($category !== 'discretionary' || ! str_contains($label, 'insurance')) {
            return $category;
        }

        foreach (['building', 'contents', 'home', 'house', 'property'] as $keyword) {
            if (str_contains($label, $keyword)) {
                return 'essential';
            }
        }

        return $category;
    }

    /**
     * The essential floor and the discretionary spend on top as age-varying paths, derived
     * from the 3-tier line items when present (essential = sum of essential line paths;
     * discretionary = sum of discretionary line paths + *spent* self-investment), else from
     * the legacy flat totals as flat paths. Each path is the exact sum of its line paths at
     * every age — the reconciliation invariant, extended from a scalar sum to a per-age sum.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: SpendPath, 1: SpendPath}
     */
    private function essentialAndDiscretionary(array $state): array
    {
        $lines = $state['expenseLines'] ?? [];
        if ($lines === []) {
            $e = $state['expense'] ?? [];

            return [
                SpendPath::flat($this->moneyRequired($e['essential'] ?? null)),
                SpendPath::flat($this->money($e['discretionary'] ?? null) ?? Money::zero()),
            ];
        }

        // The tier is read through tierOf(), not off the line, so cover of the home lands in the
        // floor whatever tier it was typed into (see tierOf). Every line still counts exactly once.
        $essential = $this->sumLinePaths($lines, static fn (array $l): bool => self::tierOf($l) === 'essential');
        $discretionary = $this->sumLinePaths($lines, static fn (array $l): bool => self::tierOf($l) === 'discretionary'
            || (($l['category'] ?? '') === 'self_investment' && ! ($l['savedAsAsset'] ?? false)));

        return [$essential, $discretionary];
    }

    /**
     * The age-varying path for a single expense line. A line whose spend changes with age
     * carries `bands` — change breakpoints `{fromAge, amount}` at ages above the start — on top
     * of its base `amount` (which holds from the start until the first band). Only an
     * *always*-condition line may smile: a contingent cost (mortgage / service charge / commute)
     * is flat and stops by its condition, so a band on it is ignored (a v1 limit — contingent
     * costs do not smile). A line with no bands is a flat path at its amount.
     *
     * @param  array<string, mixed>  $line
     */
    private function linePath(array $line): SpendPath
    {
        $base = Money::fromPence($this->toPence((string) ($line['amount'] ?? '0')));
        $bands = $line['bands'] ?? [];

        if (! is_array($bands) || $bands === [] || $this->lineCondition($line) !== 'always') {
            return SpendPath::flat($base);
        }

        // The base holds from age 0 (so it covers every pre-band age); each band steps from its
        // own age. Bands at or below 0 would collide with the base band, so they are dropped; a
        // duplicate age coalesces to the last entry (never a crash on hand-entered data).
        $byAge = [0 => $base];
        foreach ($bands as $band) {
            $fromAge = (int) ($band['fromAge'] ?? 0);
            if ($fromAge <= 0) {
                continue;
            }
            $byAge[$fromAge] = Money::fromPence($this->toPence((string) ($band['amount'] ?? '0')));
        }

        $breakpoints = [];
        foreach ($byAge as $fromAge => $amount) {
            $breakpoints[] = ['fromAge' => $fromAge, 'amount' => $amount];
        }

        return SpendPath::fromBands($breakpoints);
    }

    /**
     * The sum of the paths of the lines matching the predicate — a per-age total that reconciles
     * to the sum of the lines at every age. Zero (flat) when no line matches.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function sumLinePaths(array $lines, callable $predicate): SpendPath
    {
        $path = SpendPath::flat(Money::zero());
        foreach ($lines as $line) {
            if ($predicate($line)) {
                $path = $path->plus($this->linePath($line));
            }
        }

        return $path;
    }

    /**
     * The household's accounts, plus — when there is *saved* self-investment (a
     * self-investment line flagged `savedAsAsset`) — a synthetic ISA whose ongoing
     * contributions are that saved amount. One home per pound: the saved line **is**
     * the contribution (funded from surplus, growing net worth), never also an account
     * balance, so it is counted once (gotcha O).
     *
     * @param  array<string, mixed>  $state
     * @return list<Account>
     */
    private function accounts(array $state): array
    {
        $accounts = array_map($this->account(...), $state['accounts'] ?? []);

        $saved = $this->sumLines(
            $state['expenseLines'] ?? [],
            fn (array $l): bool => ($l['category'] ?? '') === 'self_investment' && ($l['savedAsAsset'] ?? false),
        );

        if ($saved->isPositive()) {
            $accounts[] = new Account(
                ownerId: (string) ($state['people'][0]['id'] ?? 'p1'),
                type: AccountType::Isa,
                balance: Money::zero(),
                unrealisedGain: null,
                yield: null,
                ongoingContributions: $saved,
            );
        }

        return $accounts;
    }

    /**
     * Sum the (exact-pence) amounts of the expense lines matching $predicate. $key names the field
     * summed, so the same reduction serves a line's own amount and a sub-figure carried on it (the
     * utilities inside a service charge).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private function sumLines(array $lines, callable $predicate, string $key = 'amount'): Money
    {
        $pence = 0;
        foreach ($lines as $line) {
            if ($predicate($line)) {
                $pence += $this->toPence((string) ($line[$key] ?? '0'));
            }
        }

        return Money::fromPence($pence);
    }

    private function pension(array $p): DcPension|DbPension|StatePensionEntitlement
    {
        return match ($p['subtype']) {
            'dc' => new DcPension(
                ownerId: (string) $p['ownerId'],
                currentValue: $this->moneyRequired($p['currentValue'] ?? null),
                ongoingContribution: $this->money($p['ongoingContribution'] ?? null) ?? Money::zero(),
                employerContribution: $this->money($p['employerContribution'] ?? null) ?? Money::zero(),
                earliestAccessAge: (int) $p['earliestAccessAge'],
                withdrawalPlan: array_map($this->withdrawal(...), $p['withdrawals'] ?? []),
                pclsTakenToDate: $this->money($p['pclsTakenToDate'] ?? null),
                growthAssumptionOverride: $this->percent($p['growthAssumptionOverride'] ?? null),
                annuityPurchase: $this->annuity($p),
                // Blank = relief not modelled (the pre-2026-07-31 behaviour), so an existing
                // scenario does not silently shift; surfaced as an input note rather than assumed.
                reliefMethod: ($p['reliefMethod'] ?? '') === ''
                    ? null
                    : PensionReliefMethod::from((string) $p['reliefMethod']),
                // Who the scheme would pay on death. Blank = nobody was asked, which the DTO reads
                // as NOT the spouse (the adverse answer) and the results page discloses.
                nominatedBeneficiary: ($p['nominatedBeneficiary'] ?? '') === ''
                    ? null
                    : PensionBeneficiary::from((string) $p['nominatedBeneficiary']),
            ),
            'db' => new DbPension(
                ownerId: (string) $p['ownerId'],
                accruedAnnualPension: $this->moneyRequired($p['accruedAnnualPension'] ?? null),
                normalRetirementAge: (int) $p['normalRetirementAge'],
                revaluationBasis: PensionEscalationBasis::from($p['revaluationBasis'] ?? 'cpi'),
                escalationInPayment: PensionEscalationBasis::from($p['escalationInPayment'] ?? 'cpi'),
                spousePensionFraction: $this->percent($p['spousePensionFraction'] ?? null),
                commutationLumpSum: $this->money($p['commutationLumpSum'] ?? null),
                commutationFactor: $this->floatOrNull($p['commutationFactor'] ?? null),
                // Blank = take the engine's disclosed default for a Fixed-basis scheme, so a
                // scenario saved before this input existed does not silently shift and no
                // what-if child records a delta for it.
                fixedEscalationRate: $this->percent($p['fixedEscalationRate'] ?? null),
            ),
            'state' => new StatePensionEntitlement(
                ownerId: (string) $p['ownerId'],
                weeklyForecast: $this->money($p['weeklyForecast'] ?? null),
                qualifyingYears: $this->intOrNull($p['qualifyingYears'] ?? null),
                deferralWeeks: (int) ($p['deferralWeeks'] ?? 0),
            ),
        };
    }

    private function withdrawal(array $w): WithdrawalInstruction
    {
        return new WithdrawalInstruction(
            kind: match ($w['kind']) {
                'pcls' => WithdrawalKind::Pcls,
                'ufpls' => WithdrawalKind::Ufpls,
                'drawdown' => WithdrawalKind::DrawdownIncome,
            },
            amount: $this->moneyRequired($w['amount'] ?? null),
            atAge: (int) $w['atAge'],
        );
    }

    /**
     * The optional annuity purchase on a DC pot: null unless the annuitise toggle is on and the
     * amount + age are set (so an incomplete toggle silently builds nothing). The rate defaults to
     * a sourced ~7.2% (level joint-life at 65) and the survivor fraction to 50% for a joint annuity.
     *
     * @param  array<string, mixed>  $p
     */
    private function annuity(array $p): ?AnnuityPurchase
    {
        if (empty($p['annuitise'])) {
            return null;
        }

        $amount = $this->money($p['annuityAmount'] ?? null);
        $atAge = $this->intOrNull($p['annuityAtAge'] ?? null);
        if ($amount === null || $atAge === null) {
            return null;
        }

        return new AnnuityPurchase(
            atAge: $atAge,
            amount: $amount,
            rate: $this->percent($p['annuityRate'] ?? null) ?? Percent::fromPercent(7.2),
            escalation: PensionEscalationBasis::from($p['annuityEscalation'] ?? 'none'),
            survivorFraction: empty($p['annuityJoint'])
                ? null
                : ($this->percent($p['annuitySurvivorFraction'] ?? null) ?? Percent::fromPercent(50)),
        );
    }

    private function account(array $a): Account
    {
        return new Account(
            ownerId: (string) $a['ownerId'],
            type: AccountType::from($a['type']),
            balance: $this->moneyRequired($a['balance'] ?? null),
            unrealisedGain: $this->money($a['unrealisedGain'] ?? null),
            yield: $this->percent($a['yield'] ?? null),
        );
    }

    private function incomeStream(array $s): IncomeStream
    {
        $type = IncomeStreamType::from($s['type']);

        return new IncomeStream(
            ownerId: (string) $s['ownerId'],
            type: $type,
            // The amount is entered per a chosen pay frequency (weekly / 4-weekly / monthly /
            // annual) and annualised here — the single conversion boundary. 4-weekly matters:
            // the DWP pays State Pension and DLA / AA every four weeks, not monthly.
            grossAnnual: $this->annualised($this->moneyRequired($s['grossAnnual'] ?? null), (string) ($s['frequency'] ?? 'annual')),
            // A tax-free benefit (DLA / AA / PIP) is structurally tax-free — the type overrides any
            // stale "taxable" flag, so it can never be income-taxed or counted in the means test.
            taxable: $type->isTaxFreeBenefit() ? false : (bool) ($s['taxable'] ?? false),
            inflationLinked: (bool) ($s['inflationLinked'] ?? false),
            startAge: (int) $s['startAge'],
            endAge: $this->intOrNull($s['endAge'] ?? null),
        );
    }

    /**
     * A documented one-off capital receipt (a family gift / inheritance / outside-asset sale):
     * who receives it, what it is, how much (today's money) and which calendar year it lands.
     */
    private function capitalReceipt(array $r): CapitalReceipt
    {
        return new CapitalReceipt(
            ownerId: (string) $r['ownerId'],
            label: (string) ($r['label'] ?? ''),
            amount: $this->moneyRequired($r['amount'] ?? null),
            calendarYear: (int) $r['year'],
        );
    }

    /** Convert a per-period amount to an annual figure (annual is the default / back-compat). */
    private function annualised(Money $amount, string $frequency): Money
    {
        return $amount->times(match ($frequency) {
            'weekly' => 52,
            'four_weekly' => 13,
            'monthly' => 12,
            default => 1,
        });
    }

    /** @param list<array<string, mixed>> $people the household's members, in the order entered */
    private function property(array $p, int $saleYear, array $people = []): Property
    {
        return new Property(
            currentValue: $this->moneyRequired($p['currentValue'] ?? null),
            ownership: OwnershipType::from($p['ownership']),
            isPrimaryResidence: true,
            everLet: (bool) ($p['everLet'] ?? false),
            outstandingMortgage: $this->money($p['outstandingMortgage'] ?? null),
            runningCosts: $this->money($p['runningCosts'] ?? null),
            growthAssumptionOverride: $this->percent($p['growthAssumptionOverride'] ?? null),
            ownershipShare: $this->percent($p['ownershipShare'] ?? null),
            cgtHistory: $this->cgtHistoryFrom($p, $saleYear),
            mortgageRedemptionYear: $this->intOrNull($p['mortgageRedemptionYear'] ?? null),
            mortgageMaturityAction: MortgageMaturityAction::from($p['mortgageMaturityAction'] ?? 'refinance'),
            isLet: (bool) ($p['isLet'] ?? false),
            mortgageRollUpRate: $this->percent($p['mortgageRollUpRate'] ?? null),
            mortgageOverpaymentAnnual: $this->money($p['mortgageOverpayment'] ?? null),
            repaymentTerms: $this->repaymentTermsFrom($p, $saleYear),
            // What letting costs, as a share of gross rent. Blank means "no figure given" and takes
            // the engine's disclosed default; an explicit 0 is the reader's own figure and wins.
            lettingManagementRate: $this->percent($p['lettingManagementRate'] ?? null),
            lettingVoidRate: $this->percent($p['lettingVoidRate'] ?? null),
            lettingMaintenanceRate: $this->percent($p['lettingMaintenanceRate'] ?? null),
            // Council tax as its own line. Blank means it is still bundled inside the running
            // costs above, which is how every scenario stored before card 0047 behaved: charged
            // in full for life, with no discount and no reduction. The band is set only when the
            // disabled band reduction applies, so blank is the ordinary case.
            annualCouncilTax: $this->money($p['councilTax'] ?? null),
            disabledBandReduction: CouncilTaxBand::tryFrom((string) ($p['councilTaxDisabledBand'] ?? '')),
            // Somebody the care means test must disregard the home for lives here (card 0055).
            // Absent means false, the adverse answer: nobody qualifies, so the home counts.
            occupiedByQualifyingRelative: (bool) ($p['occupiedByQualifyingRelative'] ?? false),
            // A park home or similar chattel (card 0059). Absent or blank means nobody was asked,
            // and the engine then reads a home modelled as losing value as a park home, which is
            // the adverse answer. An explicit yes or no is theirs and wins.
            isChattelDwelling: match ((string) ($p['isChattelDwelling'] ?? '')) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            beneficialShares: $this->beneficialShares($p, $people),
        );
    }

    /**
     * Who owns how much of the home, keyed by person id. The builder asks ONE question, the first
     * member's share, and the rest of the household takes what is left in equal parts, because a
     * share and its complement are the same fact and storing both invites them to disagree. Blank
     * means nobody said, and the engine then splits the home equally and discloses that it did.
     *
     * @param  array<string, mixed>  $p
     * @param  list<array<string, mixed>>  $people
     * @return array<string, Percent>|null
     */
    private function beneficialShares(array $p, array $people): ?array
    {
        $yours = $this->percent($p['beneficialShareYours'] ?? null);
        if ($yours === null || count($people) < 2) {
            return null;
        }

        $others = count($people) - 1;
        $rest = Percent::fromBasisPoints((int) round((10_000 - $yours->basisPoints) / $others));

        $shares = [];
        foreach ($people as $i => $person) {
            $shares[(string) $person['id']] = $i === 0 ? $yours : $rest;
        }

        return $shares;
    }

    /**
     * The terms of a capital-and-interest ("repayment") mortgage, from the builder's mortgage
     * inputs. Null — the common case — leaves the pre-existing shapes untouched: a static balance
     * (interest-only / RIO, whose interest is a "Mortgage" expense line) or a lifetime-mortgage
     * roll-up.
     *
     * The term is what switches it on: without a term there is nothing to amortise over. The
     * amount borrowed is NOT taken here — it is `outstandingMortgage` on the same property, the
     * one home for "what is owed", so the schedule can never amortise a different loan from the
     * one the rest of the forecast sees.
     *
     * A lender quotes an initial deal rate for a fixed number of months and then a reversion rate
     * for the rest of the term; an empty reversion rate (or no initial period) means one rate
     * throughout.
     */
    private function repaymentTermsFrom(array $p, int $baseYear): ?RepaymentMortgageTerms
    {
        $termMonths = $this->intOrNull($p['mortgageRepaymentTermMonths'] ?? null);
        if ($termMonths === null || $termMonths < 1) {
            return null;
        }

        $initialRate = $this->percent($p['mortgageRepaymentRate'] ?? null) ?? Percent::zero();
        $initialMonths = $this->intOrNull($p['mortgageRepaymentInitialMonths'] ?? null);
        $revertRate = $this->percent($p['mortgageRepaymentRevertRate'] ?? null);

        // Two tiers only when there is both a deal length and a different rate to revert to, and
        // the deal is shorter than the term (otherwise the initial rate simply runs throughout).
        $periods = $initialMonths !== null && $initialMonths > 0 && $initialMonths < $termMonths && $revertRate !== null
            ? [new MortgageRatePeriod($initialRate, $initialMonths), new MortgageRatePeriod($revertRate)]
            : [new MortgageRatePeriod($initialRate)];

        return new RepaymentMortgageTerms(
            termMonths: $termMonths,
            firstPaymentYear: $this->intOrNull($p['mortgageRepaymentStartYear'] ?? null) ?? $baseYear,
            firstPaymentMonth: $this->intOrNull($p['mortgageRepaymentStartMonth'] ?? null) ?? 1,
            ratePeriods: $periods,
        );
    }

    /**
     * The Capital Gains Tax history for a home that was let / not always the main residence,
     * reduced from the wizard's occupation timeline to the months the engine needs. Returns
     * null (full Private Residence Relief, no CGT — the common case) unless the home is flagged
     * as ever-let AND a purchase price is given (without which there is no gain to tax).
     *
     * Each period in the timeline runs from its start year to the next period's start (the last
     * to the sale year); the months a period was the main residence count towards relief, a let
     * period does not. Occupation is what matters, not the mortgage (gov.uk HS283). Public so the
     * builder's live CGT readout reduces the timeline through the same one source.
     *
     * A period can also be an ALLOWED ABSENCE ("deemed occupation") — away for any reason, away
     * for a job elsewhere in the UK, or working abroad. Those months are summed per kind and
     * handed to the engine raw, which owns the statutory caps; this method owns the part only a
     * timeline can answer, which is whether the absence is bracketed by real occupation.
     *
     * @param  array<string, mixed>  $p  the property form-state
     */
    public function cgtHistoryFrom(array $p, int $saleYear): ?CgtHistory
    {
        $h = $p['cgtHistory'] ?? null;
        if (! ($p['everLet'] ?? false) || ! is_array($h)) {
            return null;
        }

        $purchase = $this->money($h['purchasePrice'] ?? null);
        if ($purchase === null) {
            return null; // no purchase price → no gain to compute; leave full PRR (£0)
        }

        $acquisitionYear = (int) ($h['acquisitionYear'] ?? $saleYear);
        $ownershipMonths = max(0, ($saleYear - $acquisitionYear) * 12);

        // Sum the months each timeline period was the main residence; a period runs to the next
        // period's start year, the last to the sale year. Sorted defensively by start year.
        $periods = array_values(array_filter(
            is_array($h['periods'] ?? null) ? $h['periods'] : [],
            static fn ($period): bool => is_array($period) && ($period['fromYear'] ?? '') !== '',
        ));
        usort($periods, static fn (array $a, array $b): int => (int) $a['fromYear'] <=> (int) $b['fromYear']);

        // Which periods are actual occupation: the test a period of absence is judged against.
        $occupied = array_map(static fn (array $p): bool => ($p['use'] ?? 'main_home') === 'main_home', $periods);

        $mainResidenceMonths = 0;
        $absence = ['absence_any' => 0, 'absence_uk_work' => 0, 'absence_abroad' => 0];
        foreach ($periods as $i => $period) {
            $from = (int) $period['fromYear'];
            $to = isset($periods[$i + 1]) ? (int) $periods[$i + 1]['fromYear'] : $saleYear;
            $months = max(0, ($to - $from) * 12);
            $use = $period['use'] ?? 'main_home';

            if ($use === 'main_home') {
                $mainResidenceMonths += $months;

                continue;
            }
            if (! array_key_exists($use, $absence)) {
                continue; // let / not the main home — chargeable, no relief
            }

            // Deemed occupation (TCGA 1992 s223(3), gov.uk HS283 "Absence from your home"):
            // an absence only counts as living there if the home was the owner's main
            // residence before it AND they came back to it afterwards. The two work absences
            // are excused the coming-back test, because the statute allows for a job that
            // stops the owner returning. An absence that fails its test simply earns no
            // relief — the adverse reading, and the same treatment as a let period.
            $before = in_array(true, array_slice($occupied, 0, $i), true);
            $after = in_array(true, array_slice($occupied, $i + 1), true);
            if (! $before || ($use === 'absence_any' && ! $after)) {
                continue;
            }
            $absence[$use] += $months;
        }

        return new CgtHistory(
            purchasePrice: $purchase,
            improvementCosts: $this->money($h['improvementCosts'] ?? null) ?? Money::zero(),
            ownershipMonths: $ownershipMonths,
            mainResidenceMonths: min($mainResidenceMonths, $ownershipMonths),
            higherRateOnSale: (bool) ($h['higherRateOnSale'] ?? false),
            owners: ($h['jointlyOwned'] ?? false) ? 2 : 1,
            // Raw qualifying months: the engine owns the 3-year / 4-year statutory caps.
            absenceAnyReasonMonths: $absence['absence_any'],
            absenceWorkElsewhereUkMonths: $absence['absence_uk_work'],
            absenceWorkAbroadMonths: $absence['absence_abroad'],
        );
    }

    // --- primitive parsing (no float in money) -----------------------------------

    private function money(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::fromPence($this->toPence((string) $value));
    }

    private function moneyRequired(mixed $value): Money
    {
        return $this->money($value) ?? Money::zero();
    }

    /** Parse a decimal pounds string to exact integer pence, no float involved. */
    private function toPence(string $value): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $pence = (int) ($whole === '' ? '0' : $whole) * 100 + (int) ($fraction === '' ? '0' : $fraction);

        return $negative ? -$pence : $pence;
    }

    private function percent(mixed $value): ?Percent
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Percent::fromPercent((float) $value);
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function date(string $iso): DateTimeImmutable
    {
        return Codec::date($iso);
    }
}
