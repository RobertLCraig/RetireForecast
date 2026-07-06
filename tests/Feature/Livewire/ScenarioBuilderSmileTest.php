<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * Slice 5 of the spending smile: the per-line "spending changes with age" band editor round-trips
 * through builder_state to the assembled forecast, and the sparse-storage discipline holds (a
 * blank band row does not block save nor persist, and a line with no bands stores no `bands` key,
 * so a what-if that changes nothing records no spurious delta).
 */
class ScenarioBuilderSmileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** @return Testable */
    private function minimal()
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    public function test_a_spending_smile_band_round_trips_to_the_forecast(): void
    {
        $this->minimal()
            ->call('addExpenseLine', 'discretionary')
            ->set('expenseLines.1.label', 'Holidays')
            ->set('expenseLines.1.amount', '6000')
            ->call('addSpendBand', 1)
            ->set('expenseLines.1.bands.0.fromAge', '75')
            ->set('expenseLines.1.bands.0.amount', '2000')
            ->call('save')
            ->assertHasNoErrors();

        $profile = Scenario::firstOrFail()->toHousehold()->expenseProfile;

        $this->assertTrue($profile->hasSmile());
        $this->assertSame(600_000, $profile->discretionaryAnnualSpendAt(70)->pence);
        $this->assertSame(200_000, $profile->discretionaryAnnualSpendAt(75)->pence);
    }

    public function test_a_blank_band_row_does_not_block_save_and_stores_no_bands(): void
    {
        $this->minimal()
            ->call('addExpenseLine', 'discretionary')
            ->set('expenseLines.1.label', 'Holidays')
            ->set('expenseLines.1.amount', '6000')
            ->call('addSpendBand', 1) // left blank
            ->call('save')
            ->assertHasNoErrors();

        $lines = Scenario::firstOrFail()->builder_state['expenseLines'];
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('bands', $line, 'a line with only a blank band must persist no bands');
        }
    }

    public function test_a_non_numeric_band_age_is_a_validation_error(): void
    {
        $this->minimal()
            ->call('addExpenseLine', 'discretionary')
            ->set('expenseLines.1.label', 'Holidays')
            ->set('expenseLines.1.amount', '6000')
            ->call('addSpendBand', 1)
            ->set('expenseLines.1.bands.0.fromAge', 'seventy')
            ->set('expenseLines.1.bands.0.amount', '2000')
            ->call('save')
            ->assertHasErrors('expenseLines.1.bands.0.fromAge');

        $this->assertSame(0, Scenario::count());
    }
}
