<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * The sweep levers and the protection-gap stress all vary ONE part of a household and keep the
 * rest. They used to do that by rebuilding {@see Household} positionally in seven separate places,
 * which meant a field added to the DTO and not to a lever was silently dropped from every swept
 * forecast: a carefully-entered input that quietly stops counting, exactly the failure the
 * data-layer integrity rule exists to prevent.
 *
 * Rebuilding now happens in ONE private `copy()`, and this test is the guard on it. It is
 * reflection-driven on purpose: it enumerates the DTO's own properties rather than a hand-written
 * checklist, so a NEW field is covered the moment it is declared, without anyone remembering to
 * add it here. A field dropped from `copy()` fails this test rather than a forecast.
 */
final class HouseholdWitherTest extends TestCase
{
    /** A household with every field set to something distinctive, so a dropped one is detectable. */
    private function fullyPopulated(): Household
    {
        return new Household(
            name: 'Wither',
            region: RegionProfile::Scotland,
            persons: [new Person('p1', new DateTimeImmutable('1960-06-01'), Sex::Male, EmploymentStatus::Retired)],
            expenseProfile: new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(5_000), Percent::fromPercent(70)),
            pensions: [new DbPension('p1', Money::fromPounds(9_000), 65)],
            accounts: [new Account('p1', AccountType::Isa, Money::fromPounds(30_000))],
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(1_200), false, false, 60)],
            primaryResidence: new Property(Money::fromPounds(300_000), OwnershipType::Mortgaged, outstandingMortgage: Money::fromPounds(50_000)),
            relationshipStatus: RelationshipStatus::Cohabiting,
            capitalReceipts: [new CapitalReceipt('p1', 'Gift', Money::fromPounds(10_000), 2030)],
            realisedGainsAtStart: ['p1' => Money::fromPounds(1_000)],
            formerResidenceDisposal: new ResidenceDisposal(Money::fromPounds(400_000), 2026),
        );
    }

    /** @return list<string> */
    private function fieldNames(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(Household::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $names[] = $property->getName();
        }

        return $names;
    }

    /**
     * Every wither changes exactly the field it names and carries every other field through
     * untouched. Driven off the DTO's own property list, so a new field is guarded automatically.
     */
    public function test_each_wither_changes_only_its_own_field(): void
    {
        $base = $this->fullyPopulated();

        $cases = [
            'persons' => fn (): Household => $base->withPersons([new Person('p2', new DateTimeImmutable('1970-01-01'), Sex::Female, EmploymentStatus::Employed)]),
            'pensions' => fn (): Household => $base->withPensions([]),
            'expenseProfile' => fn (): Household => $base->withExpenseProfile(new ExpenseProfile(Money::fromPounds(1), Money::zero(), Percent::fromPercent(100))),
            'capitalReceipts' => fn (): Household => $base->withCapitalReceipts([]),
        ];

        $fields = $this->fieldNames();
        $this->assertNotEmpty($fields);

        foreach ($cases as $changed => $make) {
            $this->assertContains($changed, $fields, "{$changed} is not a Household field any more — update this test");
            $copy = $make();

            foreach ($fields as $field) {
                if ($field === $changed) {
                    $this->assertNotEquals($base->{$field}, $copy->{$field}, "withXxx() did not change {$field}");

                    continue;
                }
                $this->assertEquals(
                    $base->{$field},
                    $copy->{$field},
                    "changing {$changed} silently dropped or altered {$field} — add it to Household::copy()",
                );
            }
        }
    }

    /**
     * The reflection above only proves a field survives a copy. This proves the DTO has not grown a
     * field that `copy()` never learned about: `copy()`'s own reconstruction must mention every
     * public property by name. A new field added to the constructor and forgotten in `copy()` would
     * still pass the test above (it would carry its DEFAULT through, which equals the base only by
     * luck), so read the source and require the name.
     */
    public function test_the_single_copy_method_names_every_household_field(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(Household::class))->getFileName());
        $start = (int) strpos($source, 'private function copy(');
        $end = strpos($source, 'public function', $start);
        $body = $end === false ? substr($source, $start) : substr($source, $start, $end - $start);

        foreach ($this->fieldNames() as $field) {
            $this->assertStringContainsString(
                '$this->'.$field,
                $body,
                "Household::copy() never mentions \${$field}: a wither would silently drop it",
            );
        }
    }
}
