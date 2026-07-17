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
        // (spousal exemption + transferable nil-rate band vs a chargeable transfer). Defaulted to
        // married/civil-partnership so every existing scenario keeps today's spousal behaviour;
        // ignored for a single-person household. See {@see RelationshipStatus}.
        public readonly RelationshipStatus $relationshipStatus = RelationshipStatus::MarriedOrCivilPartnership,
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
    ) {}

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
