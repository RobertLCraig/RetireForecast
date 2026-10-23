<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\IncomeAndSpending;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Money\Money;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The one-screen "where the money comes from, what it lets you spend" page.
 */
class IncomeAndSpendingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_stage_adds_up_from_its_named_sources_to_what_is_left(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'expenseLines.ess1.amount' => '8000']);

        Livewire::test(IncomeAndSpending::class, ['scenario' => $scenario])
            ->assertOk()
            ->assertSee('Where your money comes from')
            ->assertSee('State Pension')
            ->assertSee('What you can spend')
            ->assertViewHas('money', function (array $m): bool {
                $this->assertNotEmpty($m['stages']);
                foreach (array_keys($m['stages']) as $i) {
                    $sum = Money::zero();
                    foreach ([...$m['regular'], ...$m['drawn']] as $row) {
                        $sum = $sum->plus($row['monthly'][$i]);
                    }
                    $this->assertTrue($sum->minus($m['tax'][$i])->equals($m['toSpend'][$i]), "stage {$i}: sources less tax must equal money to spend");
                    $this->assertTrue($m['toSpend'][$i]->minus($m['spend'][$i])->equals($m['leftOver'][$i]), "stage {$i}: left over must be money to spend less spend");
                    $this->assertTrue($m['essentials'][$i]->lessThanOrEqual($m['spend'][$i]));
                    // What the plan could carry never drops below the essentials it must pay.
                    $this->assertTrue($m['couldSpend'][$i]->greaterThanOrEqual($m['essentials'][$i]), "stage {$i}: could spend must cover essentials");
                }

                return true;
            });
    }

    public function test_another_users_plan_is_refused(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $this->actingAs(User::factory()->create());

        Livewire::test(IncomeAndSpending::class, ['scenario' => $scenario])->assertForbidden();
    }
}
