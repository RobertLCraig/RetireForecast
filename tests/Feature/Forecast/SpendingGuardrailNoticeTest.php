<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\SpendingGuardrail;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\TestCase;

/**
 * Board card 0063, the two REPORTING criteria. A guardrail that quietly improved the odds would be
 * exactly the invisible figure this project bans: a plan survives because the household was
 * modelled cutting back, so the reader has to be told how many years it cut back and by how much.
 * And a household with no discretionary spend left gets no protection at all, which is itself the
 * finding and has to be said out loud.
 *
 * Both notes ride {@see ResultPresenter::inputNotes()}, so the results page and the PDF carry the
 * same words off the same figures.
 */
class SpendingGuardrailNoticeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A retired household whose money cannot possibly fund its essentials for life, so the funded
     * ratio is below the trigger from the very first year.
     *
     * @return array<string, mixed>
     */
    private function state(bool $guardrailOn, string $discretionary = '6000', array $guardrail = []): array
    {
        return [
            'householdName' => 'Trimmers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '100000']],
            'expenseLines' => array_values(array_filter([
                ['id' => 'e1', 'amount' => '18000', 'category' => 'essential'],
                $discretionary === '0' ? null : ['id' => 'e2', 'amount' => $discretionary, 'category' => 'discretionary'],
            ])),
            'expense' => ['survivorFactor' => '100'] + ($guardrailOn ? ['guardrailOn' => true] + $guardrail : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{kind: string, text: string}>
     */
    private function notes(array $state): array
    {
        $assembler = new HouseholdAssembler;
        $household = $assembler->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return ResultPresenter::inputNotes($household, $forecast);
    }

    /** @param list<array{kind: string, text: string}> $notes */
    private function textOf(array $notes, string $kind): string
    {
        $kinds = array_column($notes, 'kind');
        $this->assertContains($kind, $kinds);

        return $notes[array_search($kind, $kinds, true)]['text'];
    }

    public function test_a_guardrail_reports_how_many_years_it_bit_and_by_how_much(): void
    {
        $text = $this->textOf($this->notes($this->state(true)), 'spending_guardrail');

        // The count of years, the total trimmed and the deepest single year, all read off the
        // projection itself so the sentence can never describe a run that did not happen.
        $this->assertMatchesRegularExpression('/\b\d+ of the \d+ years\b/', $text);
        $this->assertMatchesRegularExpression('/£[\d,]+\.\d\d/', $text, 'and the pounds it took off');
        $this->assertStringContainsString('discretionary', $text);
    }

    public function test_a_plan_with_no_guardrail_says_nothing_about_one(): void
    {
        // No noise: the household that spends the same whatever happens has no guardrail to report.
        $kinds = array_column($this->notes($this->state(false)), 'kind');

        $this->assertNotContains('spending_guardrail', $kinds);
        $this->assertNotContains('guardrail_no_flexibility', $kinds);
    }

    public function test_a_household_with_no_discretionary_spend_left_is_told_the_guardrail_cannot_help(): void
    {
        $notes = $this->notes($this->state(true, discretionary: '0'));
        $text = $this->textOf($notes, 'guardrail_no_flexibility');

        $this->assertStringContainsString('no discretionary spending left', $text);
        // And it must not also claim to have trimmed anything, because it trimmed nothing.
        $this->assertNotContains('spending_guardrail', array_column($notes, 'kind'));
    }

    public function test_the_guardrail_defaults_are_disclosed_with_their_values(): void
    {
        // The no-invisible-figures rule: the reader who ticked the box and typed no figures is
        // running the engine's own trigger and cut, and both move the answer. Read from the
        // constants that own them, so re-sourcing one moves the sentence with it.
        $notes = $this->notes($this->state(true));
        $assumed = implode(' ', array_column(array_filter(
            $notes,
            static fn (array $n): bool => $n['kind'] === 'assumed_figure',
        ), 'text'));

        $cut = Percent::fromBasisPoints(SpendingGuardrail::DEFAULT_DISCRETIONARY_CUT_BPS)->asPercent();
        $trigger = Percent::fromBasisPoints(SpendingGuardrail::DEFAULT_TRIGGER_FUNDED_RATIO_BPS)->asFraction();

        $this->assertStringContainsString(rtrim(rtrim(number_format($cut, 2), '0'), '.').'%', $assumed);
        $this->assertStringContainsString('funded ratio of '.rtrim(rtrim(number_format($trigger, 2), '0'), '.'), $assumed);
    }

    public function test_nothing_is_assumed_about_a_guardrail_the_reader_specified(): void
    {
        $assumed = array_values(array_filter(
            $this->notes($this->state(true, guardrail: ['guardrailTriggerRatio' => '1.25', 'guardrailCutPct' => '20'])),
            static fn (array $n): bool => $n['kind'] === 'assumed_figure' && str_contains($n['text'], 'guardrail'),
        ));

        $this->assertSame([], $assumed);
    }

    public function test_the_readers_own_trigger_and_cut_reach_the_household(): void
    {
        // The editable half of the criterion: what the builder stores is what the engine runs.
        $household = (new HouseholdAssembler)->household(
            $this->state(true, guardrail: ['guardrailTriggerRatio' => '1.25', 'guardrailCutPct' => '20'])
        );
        $guardrail = $household->expenseProfile->spendingGuardrail;

        $this->assertNotNull($guardrail);
        $this->assertSame(12_500, $guardrail->triggerFundedRatio()->basisPoints);
        $this->assertSame(2_000, $guardrail->discretionaryCut()->basisPoints);
    }

    public function test_no_guardrail_reaches_the_household_when_the_box_is_not_ticked(): void
    {
        $this->assertNull(
            (new HouseholdAssembler)->household($this->state(false))->expenseProfile->spendingGuardrail,
        );
    }
}
