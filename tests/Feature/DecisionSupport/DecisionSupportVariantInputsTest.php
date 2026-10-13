<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\AdviceCostComparison;
use App\DecisionSupport\SustainableSpend;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Dto\Household;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * The sustainable-spend search and the advice-cost comparison read the plan on display through
 * ScenarioForecaster::variantInputs(), the one memoised home for "this household as the housing
 * choice leaves it" (board card 0105). Two hand-rolled copies used to derive the household a second
 * time beside the memo, and one of them had no stay-put fallback for a variant it did not know.
 */
final class DecisionSupportVariantInputsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function saved(array $overrides = []): Scenario
    {
        $state = array_merge(BuilderStateFixture::full(), ['name' => 'Inputs', 'baseTaxYear' => '2026-27', 'variant' => 'stay_put'], $overrides);

        $scenario = new Scenario;
        $scenario->user_id = User::factory()->create()->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario->fresh();
    }

    /**
     * The same saved row, as a probe that counts each household derivation. ScenarioForecaster is
     * final, so the count has to be taken on the model it derives from.
     */
    private function probe(Scenario $saved): Scenario
    {
        $probe = new class extends Scenario
        {
            public int $derivations = 0;

            public function toHousehold(): Household
            {
                $this->derivations++;

                return parent::toHousehold();
            }
        };
        $probe->setTable($saved->getTable());
        $probe->setRawAttributes($saved->getAttributes(), true);
        $probe->exists = true;

        return $probe;
    }

    public function test_the_decision_support_classes_derive_the_household_once(): void
    {
        $saved = $this->saved();

        $spend = $this->probe($saved);
        app(SustainableSpend::class)->forScenario($spend);
        $this->assertSame(1, $spend->derivations, 'The sustainable-spend search derived the household beside the memo.');

        // A fresh request-scoped forecaster, or the first probe's warm memo hides a second derivation.
        $this->app->forgetScopedInstances();
        $advice = $this->probe($saved);
        app(AdviceCostComparison::class)->forScenario($advice);
        $this->assertSame(1, $advice->derivations, 'The advice-cost comparison derived the household beside the memo.');
    }

    public function test_an_unknown_variant_falls_back_to_stay_put(): void
    {
        // The builder's validation never writes this, but a hand-edited or migrated row can.
        $unknown = $this->saved(['variant' => 'not_a_variant']);
        $stayPut = $this->saved();

        $this->assertEquals(
            app(SustainableSpend::class)->forScenario($stayPut),
            app(SustainableSpend::class)->forScenario($unknown),
        );
        $this->assertEquals(
            app(AdviceCostComparison::class)->forScenario($stayPut),
            app(AdviceCostComparison::class)->forScenario($unknown),
        );
        $this->assertEquals(
            app(AdviceCostComparison::class)->forScenario($stayPut),
            app(AdviceCostComparison::class)->forScenario($stayPut, 'not_a_variant'),
        );
    }
}
