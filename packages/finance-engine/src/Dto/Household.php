<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * The whole household the forecast runs on: one or two people and everything they
 * own and spend. This is the canonical input shape the engine, storage and UI all
 * map to.
 *
 * Income tax is assessed per person (UK taxes individuals separately); capital and
 * means-tested benefits are assessed at household level. The forecast resolves
 * ownership by matching each pension/account/income stream's ownerId to a person.
 */
final class Household
{
    /**
     * @param  list<Person>  $persons  one or two
     * @param  list<Pension>  $pensions
     * @param  list<Account>  $accounts
     * @param  list<IncomeStream>  $incomeStreams
     * @param  list<CapitalReceipt>  $capitalReceipts
     * @param  array<string, Money>  $realisedGainsAtStart
     */
    public function __construct(
        public readonly string $name,
        public readonly RegionProfile $region,
        public readonly array $persons,
        public readonly ExpenseProfile $expenseProfile,
        public readonly array $pensions = [],
        public readonly array $accounts = [],
        public readonly array $incomeStreams = [],
        public readonly ?Property $primaryResidence = null,
        // How the two people are related, which drives the Inheritance Tax treatment on death
        // (spousal exemption + transferable nil-rate band vs a chargeable transfer). NULL means
        // nobody was asked: it is the one input where the wrong value is catastrophic, so it has no
        // default any more and a null is DISCLOSED rather than quietly read as married. Read it
        // through {@see relationshipStatus()}, never off the property. Ignored for a single-person
        // household. See {@see RelationshipStatus}.
        public readonly ?RelationshipStatus $relationshipStatus = null,
        // Documented one-off capital inflows (a family gift, an inheritance, the sale of
        // something outside the plan), credited to cash in their calendar year. See
        // {@see CapitalReceipt} — the no-magic-money rule's input for money arriving from
        // outside the modelled assets.
        public readonly array $capitalReceipts = [],
        // GIA gains already realised AT the base date, personId => gain — set only by the
        // housing buy transform when it funds a purchase gap by selling GIA holdings at year 0.
        // The projector charges the CGT in year 0 and counts the gain against that year's
        // annual exempt amount, so a year-0 disposal is taxed exactly once, never silently.
        public readonly array $realisedGainsAtStart = [],
        // The former main home this plan has already SOLD at the base date — set only by the
        // housing sell transforms ({@see \RetireForecast\FinanceEngine\Housing\HousingComparison}),
        // which hand the projector a household that has no home, or a cheaper one, and no record
        // of the sale. It is the fact the Inheritance Tax downsizing addition is computed from, so
        // without it every sell plan is taxed as though the residence nil-rate band were simply
        // thrown away. See {@see ResidenceDisposal}.
        public readonly ?ResidenceDisposal $formerResidenceDisposal = null,
        // The date of the marriage or civil partnership, ISO Y-m-d. Recorded because it decides
        // which State Pension inheritance rules a survivor falls under, which turn on whether the
        // couple were married before 6 April 2016. Null = not given. Captured, not yet consumed:
        // no rule here reads it, and no figure moves with it.
        public readonly ?string $marriageDate = null,
    ) {}

    /**
     * The relationship status in force, with the engine's fallback where the reader gave none.
     * The fallback stays married/civil-partnership so a scenario stored before the question
     * existed keeps the figures it was saved with; it is NOT the adverse answer, which is exactly
     * why {@see relationshipStatusIsAssumed} exists and the result page has to say so.
     */
    public function relationshipStatus(): RelationshipStatus
    {
        return $this->relationshipStatus ?? RelationshipStatus::MarriedOrCivilPartnership;
    }

    /**
     * Was the relationship status supplied by the ENGINE rather than chosen? True whenever nobody
     * answered. Only meaningful for a two-person household, where the answer decides the spouse
     * exemption, both transferable bands, State Pension inheritance and whether a survivor's
     * pension is paid at all.
     */
    public function relationshipStatusIsAssumed(): bool
    {
        return $this->relationshipStatus === null && count($this->persons) > 1;
    }

    /**
     * The same household with different people (immutable) — a sweep lever varying a retirement
     * age, a lifespan, a State Pension deferral, or the protection-gap stress killing one partner.
     *
     * @param  list<Person>  $persons
     */
    public function withPersons(array $persons): self
    {
        return $this->copy(persons: $persons);
    }

    /**
     * The same household with different pensions (immutable) — a sweep lever varying a survivor's
     * DB or annuity fraction.
     *
     * @param  list<Pension>  $pensions
     */
    public function withPensions(array $pensions): self
    {
        return $this->copy(pensions: $pensions);
    }

    /** The same household with a different expense profile (immutable) — the spend levers. */
    public function withExpenseProfile(ExpenseProfile $expenseProfile): self
    {
        return $this->copy(expenseProfile: $expenseProfile);
    }

    /**
     * The same household with different one-off capital receipts (immutable) — the protection-gap
     * solve, which sizes the life cover a survivor would need by landing a lump sum on the death.
     *
     * @param  list<CapitalReceipt>  $capitalReceipts
     */
    public function withCapitalReceipts(array $capitalReceipts): self
    {
        return $this->copy(capitalReceipts: $capitalReceipts);
    }

    /**
     * The same household with different savings/investment accounts (immutable) — the
     * capacity-for-loss stress, which marks every balance down by the fall being tested.
     *
     * @param  list<Account>  $accounts
     */
    public function withAccounts(array $accounts): self
    {
        return $this->copy(accounts: $accounts);
    }

    /**
     * The same household with a re-valued main home (immutable) — the capacity-for-loss stress,
     * which marks the home down so its EQUITY falls by the fraction being tested. Not nullable:
     * nothing here removes a home (selling it is a housing transform, not a wither), so `copy()`
     * needs no "set it to null" sentinel.
     */
    public function withPrimaryResidence(Property $primaryResidence): self
    {
        return $this->copy(primaryResidence: $primaryResidence);
    }

    /**
     * The ONE place a household is rebuilt from an existing one. Every caller that varies a single
     * part goes through here, so a field added to this DTO cannot be silently dropped by a lever
     * that rebuilt the household positionally and was never updated — which is exactly how a
     * carefully-entered input disappears from a swept forecast. Guarded by `HouseholdWitherTest`.
     *
     * @param  list<Person>|null  $persons
     * @param  list<Pension>|null  $pensions
     * @param  list<CapitalReceipt>|null  $capitalReceipts
     * @param  list<Account>|null  $accounts
     */
    private function copy(
        ?array $persons = null,
        ?array $pensions = null,
        ?ExpenseProfile $expenseProfile = null,
        ?array $capitalReceipts = null,
        ?array $accounts = null,
        ?Property $primaryResidence = null,
    ): self {
        return new self(
            $this->name,
            $this->region,
            $persons ?? $this->persons,
            $expenseProfile ?? $this->expenseProfile,
            $pensions ?? $this->pensions,
            $accounts ?? $this->accounts,
            $this->incomeStreams,
            $primaryResidence ?? $this->primaryResidence,
            $this->relationshipStatus,
            $capitalReceipts ?? $this->capitalReceipts,
            $this->realisedGainsAtStart,
            $this->formerResidenceDisposal,
            $this->marriageDate,
        );
    }

    public function person(string $id): ?Person
    {
        foreach ($this->persons as $person) {
            if ($person->id === $id) {
                return $person;
            }
        }

        return null;
    }
}
