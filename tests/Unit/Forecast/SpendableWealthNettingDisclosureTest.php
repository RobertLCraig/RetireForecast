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
 * Spendable wealth is now reported NET of the tax that would be due on the pension part (board
 * card 0076). The rate that netting was done at is a figure the reader never entered, and it moves
 * the headline the plans are ranked on — so under the standing no-invisible-figures rule it must be
 * stated, with its value and where it came from, not applied silently.
 *
 * @see ResultPresenter::assumedFigures()
 */
final class SpendableWealthNettingDisclosureTest extends TestCase
{
    /** @return list<string> the assumed-figure disclosures a reader would see */
    private function disclosures(bool $withPot = true): array
    {
        $state = [
            'householdName' => 'Pot holder', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1958-04-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => array_values(array_filter([
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                $withPot
                    ? ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'earliestAccessAge' => '55']
                    : null,
            ])),
            // A comfortably basic-rate income that covers the spend, so the pot is never drawn on
            // and the marginal rate the netting reads is an unambiguous 20%.
            'incomeStreams' => [[
                'id' => 'i1', 'ownerId' => 'p1', 'type' => 'other', 'grossAnnual' => '27000',
                'frequency' => 'annual', 'taxable' => true, 'startAge' => '60',
            ]],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '150000']],
            'expenseLines' => [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70', 'propertyCostsGrowthPct' => '2'],
            'hasProperty' => false,
            'housing' => [],
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $notes = ResultPresenter::inputNotes($household, $forecast, null);

        return array_values(array_map(
            static fn (array $n): string => $n['text'],
            array_filter($notes, static fn (array $n): bool => $n['kind'] === 'assumed_figure'),
        ));
    }

    public function test_the_netting_rate_is_disclosed(): void
    {
        $disclosures = $this->disclosures();
        $netting = array_values(array_filter(
            $disclosures,
            static fn (string $text): bool => str_contains($text, 'spendable'),
        ));

        $this->assertCount(1, $netting, 'the netting must be disclosed exactly once');
        $this->assertStringContainsString('20%', $netting[0], 'the rate netted at must be stated');
        $this->assertStringContainsString('marginal rate', $netting[0]);
        $this->assertStringContainsString('projected income', $netting[0], 'the rate must say where it came from');
    }

    public function test_nothing_is_claimed_where_there_is_no_pension_money_to_net(): void
    {
        $disclosures = $this->disclosures(withPot: false);
        $this->assertSame([], array_values(array_filter(
            $disclosures,
            static fn (string $text): bool => str_contains($text, 'spendable'),
        )));
    }
}
