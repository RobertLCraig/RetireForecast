<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0079, criterion #4. Whether an inherited pension is taxed at all turns on how old its
 * owner was when they died, which is a fact of the plan and not a figure the reader entered. A
 * survivor drawing a pot tax-free, or paying their own rate on every pound of it, is a difference
 * of tens of thousands of pounds, so the treatment and the age it turns on belong on the screen.
 */
final class InheritedPensionTreatmentNoticeTest extends TestCase
{
    /** @return list<array{kind: string, text: string}> */
    private function notes(int $deceasedAgeAtDeath): array
    {
        $deceasedBirthYear = 2026 - $deceasedAgeAtDeath;
        $state = [
            'householdName' => 'Inherited', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [
                ['id' => 'p1', 'dob' => "{$deceasedBirthYear}-01-01", 'sex' => 'male', 'employmentStatus' => 'retired', 'longevityMode' => 'fixed_age', 'longevityValue' => (string) $deceasedAgeAtDeath],
                ['id' => 'p2', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired', 'longevityMode' => 'fixed_age', 'longevityValue' => '95'],
            ],
            'pensions' => [
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '200'],
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '300000', 'earliestAccessAge' => '57'],
            ],
            'accounts' => [],
            'expenseLines' => [['id' => 'e1', 'amount' => '30000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => false,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $run = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), $run);

        return ResultPresenter::inputNotes($household, $forecast, $assembler->housingAction([]), null, null, $run);
    }

    private function treatmentText(int $deceasedAgeAtDeath): string
    {
        $matching = array_values(array_filter(
            $this->notes($deceasedAgeAtDeath),
            static fn (array $n): bool => $n['kind'] === 'inherited_pension_tax',
        ));
        $this->assertNotSame([], $matching, 'the reader is never told how the inherited pension is taxed');

        return implode(' ', array_column($matching, 'text'));
    }

    public function test_the_inherited_pension_tax_treatment_is_disclosed(): void
    {
        $under = $this->treatmentText(72);
        $this->assertStringContainsString('TAX-FREE', $under);
        $this->assertStringContainsString('72', $under, 'the age at death is the fact it turns on, so it is named');
        $this->assertStringContainsString('2027', $under, 'and the year the inheritance falls in');

        $over = $this->treatmentText(76);
        $this->assertStringContainsString('TAXED', $over);
        $this->assertStringContainsString('76', $over);
    }

    /** A household with nothing inherited says none of it: a disclosure that always fires is noise. */
    public function test_a_household_that_inherits_no_pension_is_told_nothing(): void
    {
        $state = [
            'householdName' => 'Alone', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '200']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '100000']],
            'expenseLines' => [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => false,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $run = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), $run);

        $kinds = array_column(ResultPresenter::inputNotes($household, $forecast, $assembler->housingAction([]), null, null, $run), 'kind');
        $this->assertNotContains('inherited_pension_tax', $kinds);
    }
}
