<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Card 0221: the results and compare pages speak plain words, and every chart says what it means.
 */
final class PlainWordsTest extends TestCase
{
    use RefreshDatabase;

    /** Words a layperson should not meet without an explanation beside them. */
    private const JARGON = 'percentile|real £|fat tail|monte[\s\x{a0}-]carlo';

    public function test_results_views_carry_no_unexplained_jargon(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        foreach (['results' => route('scenarios.results', $scenario), 'compare' => route('scenarios.compare', $scenario)] as $page => $url) {
            $html = html_entity_decode($this->get($url)->assertOk()->getContent());
            $html = preg_replace('#<script\b.*?</script>#s', '', $html);

            preg_match_all('/.{0,60}(?:'.self::JARGON.').{0,60}/iu', $html, $hits);
            $this->assertSame([], $hits[0], "The {$page} page uses jargon with no explanation beside it.");
        }
    }

    public function test_every_chart_has_a_takeaway_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        foreach (['results' => route('scenarios.results', $scenario), 'compare' => route('scenarios.compare', $scenario)] as $page => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $charts = substr_count($html, 'x-data="chart(');
            $this->assertGreaterThan(0, $charts, "The {$page} page draws no chart, so this test proves nothing.");
            // Each chart is preceded by its own takeaway, built from the figures.
            $this->assertSame($charts, preg_match_all('/data-takeaway[^>]*>[^<]*\d/', $html), "A chart on the {$page} page has no takeaway sentence.");
        }
    }
}
