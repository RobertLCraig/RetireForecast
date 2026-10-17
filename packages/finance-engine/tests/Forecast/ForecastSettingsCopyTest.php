<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RetireForecast\FinanceEngine\Dto\CouncilTaxBand;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;

/**
 * Board card 0124. {@see ForecastSettings} is rebuilt by its withers, and a rebuild that lists
 * fields by hand silently drops the one it forgot: the rent arm of the housing comparison once ran
 * with no Inheritance Tax and the ISA allowance back on, whatever the reader chose. Rebuilding now
 * happens in ONE private `copy()`, and this is the guard on it, modelled on HouseholdWitherTest.
 * It enumerates the class's own properties, so a NEW field is covered the moment it is declared.
 */
final class ForecastSettingsCopyTest extends TestCase
{
    /** Settings with every field away from its default, so a dropped one is detectable. */
    public static function fullyPopulated(): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: 2027,
            baseTaxYear: '2027-28',
            drawdownStrategy: DrawdownStrategy::FillBands,
            allocation: PortfolioAllocation::cautious40_60(),
            freezeEndYear: 2033,
            annualRent: Money::fromPounds(9_999),
            rentInflationReal: Percent::fromPercent(1.5),
            modelCareCost: true,
            sellingCosts: [new SellingCostComponent('Agent', Percent::fromPercent(1.2))],
            modelIht: true,
            homeToDescendants: false,
            useIsaAllowance: false,
            statePensionUprating: StatePensionUprating::TripleLockUntil,
            tripleLockUntilYear: 2035,
            beneficiaryMarginalRate: Percent::fromPercent(20),
            planningHorizon: PlanningHorizon::P90,
            taxableIncomeTargetPence: 1_234_500,
            hasDescendants: false,
            startingMixIsAssumed: true,
            drawdownStrategyIsChosen: true,
            rentCouncilTax: Money::fromPounds(1_111),
            rentDisabledBandReduction: CouncilTaxBand::C,
            rentLocalHousingAllowanceWeekly: Money::fromPounds(222),
        );
    }

    /** @return list<string> */
    private function fieldNames(): array
    {
        return array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(ForecastSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );
    }

    /**
     * The fixture is only a guard while it sets EVERY field away from its default: a field left
     * on its default survives a rebuild that drops it, by luck. So a field declared and not added
     * to the fixture fails here, the moment it is declared.
     */
    public function test_the_fixture_sets_every_field_away_from_its_default(): void
    {
        $settings = self::fullyPopulated();
        $fields = $this->fieldNames();
        $this->assertNotEmpty($fields);

        foreach ((new ReflectionClass(ForecastSettings::class))->getConstructor()->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $this->assertNotEquals(
                    $parameter->getDefaultValue(),
                    $settings->{$parameter->getName()},
                    "fullyPopulated() leaves {$parameter->getName()} on its default: set it, or a rebuild could drop it unseen",
                );
            }
        }
        $this->assertSame(count($fields), (new ReflectionClass(ForecastSettings::class))->getConstructor()->getNumberOfParameters());
    }

    /** Every wither changes exactly the fields it names and carries every other one through. */
    public function test_every_forecast_settings_field_survives_a_rebuild(): void
    {
        $base = self::fullyPopulated();

        $cases = [
            'withModelCareCost' => [$base->withModelCareCost(false), ['modelCareCost']],
            'withRent' => [
                $base->withRent(Money::fromPounds(5), Percent::fromPercent(0.1), Money::fromPounds(6), CouncilTaxBand::A, Money::fromPounds(7)),
                ['annualRent', 'rentInflationReal', 'rentCouncilTax', 'rentDisabledBandReduction', 'rentLocalHousingAllowanceWeekly'],
            ],
        ];

        foreach ($cases as $wither => [$copy, $changed]) {
            foreach ($this->fieldNames() as $field) {
                if (in_array($field, $changed, true)) {
                    $this->assertNotEquals($base->{$field}, $copy->{$field}, "{$wither}() did not change {$field}");

                    continue;
                }
                $this->assertEquals($base->{$field}, $copy->{$field}, "{$wither}() silently dropped or altered {$field}");
            }
        }
    }
}
