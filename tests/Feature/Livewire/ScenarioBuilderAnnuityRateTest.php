<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Pension\AnnuityRateTable;
use Tests\TestCase;

/**
 * Board card 0065, criterion #1. The annuity rate used to be a single free-text field coupled
 * to nothing: tick "rises with inflation" and you kept a LEVEL annuity's rate, which is
 * substantially too generous and moves silently. The three things a real quote is priced on
 * (the age the income starts, whether it escalates, whether it carries on to a survivor) now
 * re-derive the rate from {@see AnnuityRateTable}, on both the pension sub-form and the
 * account one, which share one partial and so must share the behaviour.
 */
class ScenarioBuilderAnnuityRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_choosing_an_escalating_annuity_recalculates_the_rate_from_the_table(): void
    {
        $component = $this->pensionAnnuity()
            ->set('pensions.0.annuityAtAge', '65')
            ->set('pensions.0.annuityEscalation', 'rpi');

        $this->assertSame(
            AnnuityRateTable::quote(65, true, null)->asPercent(),
            (float) $component->get('pensions.0.annuityRate'),
            'an index-linked annuity must not keep a level annuity\'s rate',
        );
        $this->assertLessThan(
            AnnuityRateTable::quote(65, false, null)->asPercent(),
            (float) $component->get('pensions.0.annuityRate'),
        );
    }

    public function test_changing_the_purchase_age_recalculates_the_rate_from_the_table(): void
    {
        $component = $this->pensionAnnuity()->set('pensions.0.annuityAtAge', '75');

        $this->assertSame(
            AnnuityRateTable::quote(75, false, null)->asPercent(),
            (float) $component->get('pensions.0.annuityRate'),
        );
    }

    public function test_making_the_annuity_joint_life_recalculates_the_rate_from_the_table(): void
    {
        $component = $this->pensionAnnuity()
            ->set('pensions.0.annuityAtAge', '65')
            ->set('pensions.0.annuityJoint', true);

        $this->assertSame(
            AnnuityRateTable::quote(65, false, 0.5)->asPercent(),
            (float) $component->get('pensions.0.annuityRate'),
            'a joint-life annuity buys less income than a single-life one at the same price',
        );
    }

    public function test_an_account_annuity_is_repriced_by_the_same_table(): void
    {
        $component = Livewire::test(ScenarioBuilder::class)
            ->call('addAccount')
            ->set('accounts.0.annuitise', true)
            ->set('accounts.0.annuityAtAge', '70')
            ->set('accounts.0.annuityEscalation', 'cpi');

        $this->assertSame(
            AnnuityRateTable::quote(70, true, null)->asPercent(),
            (float) $component->get('accounts.0.annuityRate'),
        );
    }

    /** A DC pot with its annuity sub-form switched on, ready to be repriced. */
    private function pensionAnnuity(): Testable
    {
        return Livewire::test(ScenarioBuilder::class)
            ->call('addPension', 'dc')
            ->set('pensions.0.currentValue', '200000')
            ->set('pensions.0.annuitise', true)
            ->set('pensions.0.annuityAmount', '100000');
    }
}
