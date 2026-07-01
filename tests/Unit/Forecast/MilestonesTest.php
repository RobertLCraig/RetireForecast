<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The life-event milestones timeline — *when* the major events happen, the legibility gap
 * Rob's "what is the 2040 event?" raised. The trust-critical properties: every date traces
 * to one source (death is the engine's deathCalendarYears, not a re-derivation), the events
 * read in calendar order, and an event that does not apply to a person is absent (a retired
 * person has no upcoming retirement).
 */
final class MilestonesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $state
     * @return array{0: Household, 1: ForecastResult}
     */
    private function forecast(array $state): array
    {
        $household = (new HouseholdAssembler)->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return [$household, $forecast];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'householdName' => 'Events', 'region' => 'england_wales_ni',
            'people' => [
                // Alex: employed, retires at 67, takes a lump sum at 65, fixed death age 85.
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1962-06-15', 'sex' => 'male', 'employmentStatus' => 'employed',
                    'grossSalary' => '40000', 'plannedRetirementAge' => '67', 'longevityMode' => 'fixed_age', 'longevityValue' => '85'],
                // Sam: already retired (so no retirement milestone), fixed death age 88.
                ['id' => 'p2', 'name' => 'Sam', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'retired',
                    'longevityMode' => 'fixed_age', 'longevityValue' => '88'],
            ],
            'pensions' => [
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '300000', 'ongoingContribution' => '0',
                    'employerContribution' => '0', 'earliestAccessAge' => '57',
                    'withdrawals' => [['kind' => 'pcls', 'amount' => '20000', 'atAge' => '65']]],
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ];
    }

    public function test_milestones_cover_the_life_events_in_calendar_order(): void
    {
        [$household, $forecast] = $this->forecast($this->state());
        $milestones = ResultPresenter::milestones($household, $forecast);
        $pairs = array_map(fn (array $m): array => [$m['year'], $m['label'], $m['age']], $milestones);

        // Alex: pension lump sum at 65 (2027), retires at 67 (2029), SPA 67 (2029, born 1962 ⇒ SPA 67).
        $this->assertContains([2027, 'Alex starts taking their pension', 65], $pairs);
        $this->assertContains([2029, 'Alex retires', 67], $pairs);
        $this->assertContains([2029, "Alex's State Pension starts", 67], $pairs);
        // Deaths from the engine's single source: Alex 1962+85=2047, Sam 1960+88=2048.
        $this->assertContains([2047, 'Alex dies', 85], $pairs);
        $this->assertContains([2048, 'Sam dies', 88], $pairs);

        // The list reads in ascending calendar order.
        $years = array_column($milestones, 'year');
        $sortedYears = $years;
        sort($sortedYears);
        $this->assertSame($sortedYears, $years);

        // Same-year tie (2029): retirement is ordered before the State Pension start.
        $this->assertLessThan(
            array_search([2029, "Alex's State Pension starts", 67], $pairs, true),
            array_search([2029, 'Alex retires', 67], $pairs, true),
        );
    }

    public function test_death_milestones_are_the_engine_single_source(): void
    {
        [$household, $forecast] = $this->forecast($this->state());

        // The engine field IS birthYear + death age — not re-derived in the presenter.
        $this->assertSame(2047, $forecast->deathCalendarYears['p1']);
        $this->assertSame(2048, $forecast->deathCalendarYears['p2']);

        $deaths = array_values(array_filter(
            ResultPresenter::milestones($household, $forecast),
            fn (array $m): bool => $m['kind'] === 'death',
        ));
        $this->assertSame([2047, 2048], array_column($deaths, 'year'));
    }

    public function test_a_retired_person_has_no_retirement_milestone(): void
    {
        [$household, $forecast] = $this->forecast($this->state());
        $labels = array_column(ResultPresenter::milestones($household, $forecast), 'label');

        // Sam is already retired — no upcoming retirement; Alex (employed) has one.
        $this->assertContains('Alex retires', $labels);
        $this->assertNotContains('Sam retires', $labels);
    }

    public function test_a_house_sale_milestone_appears_only_when_the_home_is_sold(): void
    {
        [$household, $forecast] = $this->forecast($this->state());

        // A sell strategy ($homeSold = true): the sale is a household event at the base year
        // (the proceeds are freed at year 0), with no per-person age, and it sorts first.
        $sold = ResultPresenter::milestones($household, $forecast, homeSold: true);
        $this->assertSame('house_sale', $sold[0]['kind']);
        $this->assertSame(2026, $sold[0]['year']);
        $this->assertNull($sold[0]['age']);
        $this->assertSame('The home is sold', $sold[0]['label']);

        // Default (stay put / not a sell strategy): no sale milestone.
        $kinds = array_column(ResultPresenter::milestones($household, $forecast), 'kind');
        $this->assertNotContains('house_sale', $kinds);
    }

    public function test_an_empty_forecast_yields_no_milestones(): void
    {
        $household = (new HouseholdAssembler)->household($this->state());
        $empty = new ForecastResult([], true, true, null,
            Money::zero(),
            Money::zero(), 2026);

        $this->assertSame([], ResultPresenter::milestones($household, $empty));
    }

    public function test_annotations_dodge_labels_that_fall_in_the_same_year(): void
    {
        // Two events in one year would otherwise stack their vertical labels on the same spot and
        // smear together. The second is dodged to the bottom of the plot, a third nudged deeper —
        // while an event in a year of its own stays at the top with no offset.
        $annotations = ResultPresenter::milestoneAnnotations([
            ['year' => 2026, 'age' => null, 'label' => 'The home is sold', 'kind' => 'house_sale'],
            ['year' => 2026, 'age' => 66, 'label' => "Sam's State Pension starts", 'kind' => 'state_pension'],
            ['year' => 2026, 'age' => 66, 'label' => 'Sam retires', 'kind' => 'retirement'],
            ['year' => 2030, 'age' => 70, 'label' => 'Alex dies', 'kind' => 'death'],
        ]);

        // 2026: top (0) → bottom (0) → top nudged deeper (+14); the lone 2030 event is not dodged.
        $this->assertSame(['top', 0], [$annotations[0]['label']['position'], $annotations[0]['label']['offsetY']]);
        $this->assertSame(['bottom', 0], [$annotations[1]['label']['position'], $annotations[1]['label']['offsetY']]);
        $this->assertSame(['top', 14], [$annotations[2]['label']['position'], $annotations[2]['label']['offsetY']]);
        $this->assertSame(['top', 0], [$annotations[3]['label']['position'], $annotations[3]['label']['offsetY']]);

        // The vertical line still lands on the event's year, colour-coded by kind (house_sale amber).
        $this->assertSame(2026, $annotations[0]['x']);
        $this->assertSame('#d97706', $annotations[0]['borderColor']);
    }
}
