<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

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
