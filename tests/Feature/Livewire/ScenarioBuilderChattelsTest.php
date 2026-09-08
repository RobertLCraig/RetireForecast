<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * Board card 0065, criterion #3. A one-off capital receipt could only ever be a windfall: there
 * was nowhere to say the money came from SELLING something, so a plan that turns on selling the
 * art or the jewellery paid no capital gains tax and was optimistic by the whole bill. The receipt
 * now carries what the item cost, which is the one fact that makes the sale chargeable.
 */
class ScenarioBuilderChattelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_what_a_sold_possession_cost_round_trips_into_the_forecast(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('addCapitalReceipt')
            ->set('capitalReceipts.0.year', '2030')
            ->set('capitalReceipts.0.amount', '60000')
            ->set('capitalReceipts.0.label', 'A painting')
            ->set('capitalReceipts.0.chattelCost', '5000')
            ->call('save')
            ->assertHasNoErrors();

        $receipts = Scenario::firstOrFail()->toHousehold()->capitalReceipts;

        $this->assertCount(1, $receipts);
        $this->assertNotNull($receipts[0]->chattelCost, 'the cost of the item sold should reach the engine');
        $this->assertSame(500_000, $receipts[0]->chattelCost->pence);
        $this->assertSame(6_000_000, $receipts[0]->amount->pence);
    }

    public function test_a_receipt_with_no_cost_stays_a_windfall(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('addCapitalReceipt')
            ->set('capitalReceipts.0.year', '2030')
            ->set('capitalReceipts.0.amount', '60000')
            ->set('capitalReceipts.0.label', 'Inheritance')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Scenario::firstOrFail()->toHousehold()->capitalReceipts[0]->chattelCost);
    }
}
