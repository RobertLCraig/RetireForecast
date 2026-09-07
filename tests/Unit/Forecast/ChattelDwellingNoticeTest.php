<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * What the reader is TOLD about a park home and about who pays for nursing care — board card 0059.
 *
 * Three of that card's criteria are copy the arithmetic elsewhere cannot say for itself:
 *  - a park home is a chattel, so it claims no residence nil-rate band, the site owner takes a
 *    commission on the resale, and it cannot simply be left to somebody who will not live in it;
 *  - a nursing fee is charged net of NHS-funded Nursing Care, which is a figure of ours;
 *  - NHS Continuing Healthcare, where it is awarded, removes the care charge altogether.
 *
 * Every one of them is a figure or a rule the reader never entered, so the no-invisible-figures
 * rule puts it on the screen.
 */
final class ChattelDwellingNoticeTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $property
     * @param  list<array<string, mixed>>|null  $people
     * @return list<array{kind: string, text: string}>
     */
    private function notes(array $property = [], ?array $people = null, ?ForecastSettings $settings = null): array
    {
        $people ??= [['id' => 'p1', 'dob' => '1950-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']];
        $state = [
            'householdName' => 'Pitch', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => $people,
            'pensions' => array_map(
                static fn (array $p, int $i): array => ['id' => "sp{$i}", 'ownerId' => $p['id'], 'subtype' => 'state', 'weeklyForecast' => '230'],
                $people,
                array_keys($people),
            ),
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '150000']],
            'expenseLines' => [['id' => 'e1', 'amount' => '18000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '150000', 'ownership' => 'outright'] + $property,
        ];

        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $run = $settings ?? new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), $run);

        return ResultPresenter::inputNotes($household, $forecast, $assembler->housingAction([]), null, null, $run);
    }

    /** @param list<array{kind: string, text: string}> $notes */
    private function textOf(array $notes, string $kind): string
    {
        $matching = array_values(array_filter($notes, static fn (array $n): bool => $n['kind'] === $kind));
        $this->assertNotSame([], $matching, "no note of kind {$kind} was surfaced");

        return implode(' ', array_column($matching, 'text'));
    }

    /**
     * Criteria #1 and #2, the copy half: the reader is told the band is gone, what the site owner
     * takes, and that the home cannot simply be left to somebody who will not live on the site.
     */
    public function test_a_park_home_is_told_it_has_no_residence_band_and_cannot_be_left_to_a_non_resident(): void
    {
        $text = $this->textOf($this->notes(['isChattelDwelling' => 'yes']), 'chattel_dwelling');

        $this->assertStringContainsString('residence nil-rate band', $text);
        // The commission is READ from the constant that owns it, never restated.
        $pct = rtrim(rtrim(number_format(Property::MAX_SITE_COMMISSION_BPS / 100, 2), '0'), '.');
        $this->assertStringContainsString("{$pct}%", $text);
        $this->assertStringContainsString('non-resident', $text);
    }

    /** An ordinary brick house says none of it: a disclosure that always fires is noise. */
    public function test_an_ordinary_house_gets_no_chattel_note(): void
    {
        $kinds = array_column($this->notes(), 'kind');

        $this->assertNotContains('chattel_dwelling', $kinds);
    }

    /**
     * Criteria #3 and #4: the care disclosure names the NHS contribution that comes off a nursing
     * fee, and says that Continuing Healthcare removes the whole charge where it is awarded.
     */
    public function test_the_care_disclosure_names_funded_nursing_care_and_continuing_healthcare(): void
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelCareCost: true);
        $text = $this->textOf($this->notes(settings: $settings), 'assumed_figure');

        $fnc = CareAssumptions::default()->fundedNursingCareWeekly()->format();
        $this->assertStringContainsString($fnc, $text, 'the contribution is disclosed at its own value');
        $this->assertStringContainsString('NHS-funded Nursing Care', $text);
        $this->assertStringContainsString('Continuing Healthcare', $text);
    }

    /**
     * Criterion #5, the disclosure half: nobody entered the split of the home between a couple, so
     * the equal shares the engine uses are named as its own assumption.
     */
    public function test_an_unstated_split_of_the_home_between_a_couple_is_disclosed_as_equal_shares(): void
    {
        $couple = [
            ['id' => 'p1', 'dob' => '1950-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ['id' => 'p2', 'dob' => '1952-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
        ];

        $text = $this->textOf($this->notes(people: $couple), 'assumed_figure');
        $this->assertStringContainsString('equal shares', $text);

        // Told the split, we stop assuming it.
        $stated = $this->notes(['beneficialShareYours' => '70'], people: $couple);
        $assumed = implode(' ', array_column(array_filter($stated, static fn (array $n): bool => $n['kind'] === 'assumed_figure'), 'text'));
        $this->assertStringNotContainsString('equal shares', $assumed);
    }

    /** A sole owner has no split to assume, so nothing is disclosed. */
    public function test_a_single_person_is_told_nothing_about_shares(): void
    {
        $assumed = implode(' ', array_column(array_filter($this->notes(), static fn (array $n): bool => $n['kind'] === 'assumed_figure'), 'text'));

        $this->assertStringNotContainsString('equal shares', $assumed);
    }
}
