<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\Interpretation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * "What to do next" (card 0218): one item per flag the results page already computes.
 */
final class NextStepsTest extends TestCase
{
    use RefreshDatabase;

    private const PENSION_CREDIT = [
        'howToClaim' => ['Apply online at gov.uk/pension-credit.'],
        'source' => 'https://www.gov.uk/pension-credit',
    ];

    private const SHOCK = [
        'hasOverDeduction' => true,
        'overDeduction' => '£1,234',
        'reclaimForm' => 'P55',
    ];

    public function test_next_steps_lists_a_pension_credit_claim_when_eligible(): void
    {
        $steps = Interpretation::nextSteps(self::PENSION_CREDIT, null);

        $this->assertCount(1, $steps);
        $this->assertStringContainsString('Claim Pension Credit', $steps[0]['what']);
        $this->assertStringContainsString('backdated up to 3 months', $steps[0]['what']);
        $this->assertSame('https://www.gov.uk/pension-credit', $steps[0]['source']);
    }

    public function test_next_steps_lists_the_lump_sum_reclaim(): void
    {
        $steps = Interpretation::nextSteps(null, self::SHOCK);

        $this->assertCount(1, $steps);
        $this->assertStringContainsString('£1,234', $steps[0]['what']);
        $this->assertStringContainsString('form P55', $steps[0]['what']);
        $this->assertStringStartsWith('https://www.gov.uk/', $steps[0]['source']);
    }

    public function test_next_steps_lists_only_items_whose_flag_applies(): void
    {
        $this->assertSame([], Interpretation::nextSteps(null, null));
        // A household shut out of Pension Credit is given no claim step.
        $this->assertSame([], Interpretation::nextSteps(['howToClaim' => []] + self::PENSION_CREDIT, null));
        $this->assertSame([], Interpretation::nextSteps(null, ['hasOverDeduction' => false] + self::SHOCK));
        $this->assertCount(2, Interpretation::nextSteps(self::PENSION_CREDIT, self::SHOCK));
    }

    public function test_next_steps_is_hidden_in_guidance_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);

        // The rich fixture's first withdrawal is over-taxed, so advice mode lists the reclaim.
        config()->set('compliance.personal_use', true);
        $this->get(route('scenarios.results', $scenario))->assertOk()->assertSee('data-next-steps', false);

        config()->set('compliance.personal_use', false);
        $this->get(route('scenarios.results', $scenario))->assertOk()->assertDontSee('data-next-steps', false);
    }
}
