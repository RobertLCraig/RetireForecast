<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RetireForecast\FinanceEngine\Dto\DeathInServiceCover;
use RetireForecast\FinanceEngine\Dto\DisabilityAwardRate;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The guard {@see ExpenseProfileWitherTest} puts on `ExpenseProfile`, applied to {@see Person}.
 * Card 0044 added `disabilityBenefitFromAge` and every hand rebuild of a Person had to be edited by
 * hand; one was found only by reading. Every field of the fixture is checked to differ from its
 * constructor default, so a field a copy forgets reverts to that default and goes red here.
 */
final class PersonWitherTest extends TestCase
{
    /** A person with every field set to something other than its default. */
    private function fullyPopulated(): Person
    {
        return new Person(
            id: 'p1',
            dob: new DateTimeImmutable('1962-03-14'),
            sex: Sex::Female,
            employmentStatus: EmploymentStatus::Employed,
            grossSalary: Money::fromPounds(38_000),
            salaryGrowth: Percent::fromPercent(2),
            plannedRetirementAge: 66,
            niCategory: 'A',
            name: 'Alex',
            longevity: LongevityAdjustment::offsetYears(3),
            receivesDisabilityBenefit: true,
            caresForPartner: true,
            deathInServiceCover: DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400)),
            disabilityBenefitFromAge: 75,
            disabilityAwardRate: DisabilityAwardRate::LowestRateCare,
            hasWill: true,
            ukLongTermResident: false,
        );
    }

    public function test_every_person_wither_carries_every_field(): void
    {
        $base = $this->fullyPopulated();
        $params = (new ReflectionClass(Person::class))->getConstructor()?->getParameters() ?? [];
        $this->assertNotEmpty($params);

        // Without this, a field left at its default in the fixture would survive a drop by luck.
        foreach ($params as $param) {
            if ($param->isDefaultValueAvailable()) {
                $this->assertNotSame(
                    $param->getDefaultValue(),
                    $base->{$param->getName()},
                    "the fixture leaves \${$param->getName()} at its default, so a dropped copy would go unseen — set it",
                );
            }
        }

        // wither => the one field it is allowed to change.
        $cases = [
            'withPlannedRetirementAge' => [$base->withPlannedRetirementAge(60), 'plannedRetirementAge'],
            'withLongevity' => [$base->withLongevity(LongevityAdjustment::fixedAge(90)), 'longevity'],
            'withoutDeathInServiceCover' => [$base->withoutDeathInServiceCover(), 'deathInServiceCover'],
        ];

        foreach ($cases as $wither => [$copy, $changed]) {
            $this->assertNotEquals($base->{$changed}, $copy->{$changed}, "{$wither}() did not change \${$changed}");

            foreach ($params as $param) {
                $field = $param->getName();
                if ($field === $changed) {
                    continue;
                }
                $this->assertEquals(
                    $base->{$field},
                    $copy->{$field},
                    "{$wither}() silently dropped or altered \${$field} — pass it through Person::copy()",
                );
            }
        }
    }
}
