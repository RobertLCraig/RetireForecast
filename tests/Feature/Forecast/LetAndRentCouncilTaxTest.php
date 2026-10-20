<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\QuickWhatIf;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfWriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Board card 0155, the wiring half. The engine charges a let home's household the rented home's
 * bill only if the stored plan hands it over, so this runs a real "let out & rent elsewhere" plan
 * through the forecaster the screens use.
 */
final class LetAndRentCouncilTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_let_and_rent_plan_is_charged_the_rented_homes_bill(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $base = ScenarioFixture::rich($user);
        $built = QuickWhatIf::build($base, 'let_out_and_rent');
        $built['overrides']['housing']['rentCouncilTax'] = '1234';
        $child = WhatIfWriter::create($base, $built['name'], $built['overrides']);

        $this->assertTrue($child->toHousehold()->primaryResidence->isLet, 'the plan lets its home');
        $this->assertSame(1_234_00, app(ScenarioForecaster::class)->deterministic($child)->years[0]->councilTax()->pence);
    }
}
