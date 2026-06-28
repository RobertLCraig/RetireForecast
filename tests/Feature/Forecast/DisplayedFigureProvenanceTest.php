<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Compliance\Interpretation;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Http\Controllers\ScenarioPdfController;
use App\Livewire\ScenarioResults;
use App\Models\Result;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Displayed-figure provenance: every figure a user sees must trace to ONE computed value,
 * so the same number can never be shown two different ways on two surfaces. This is the
 * data-layer integrity rule applied to the output surfaces — panel == CSV == interpretation.
 *
 * The CSV exports must reproduce the panel's own tables exactly (not re-derive from raw
 * percentiles, which could format differently), and the walled-off advice-style
 * interpretation must quote the very percentages the neutral panel shows (it uses the
 * presenter's single formatter, not its own).
 */
final class DisplayedFigureProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_fan_csv_reproduces_the_panel_fan_table_exactly(): void
    {
        [$instance, $presented] = $this->completedRun();

        $csv = $this->capture($instance->downloadFanCsv());
        $rows = $this->dataRowsAfter($csv, fn (string $line): bool => $line === 'Year,P10,P25,P50,P75,P90');

        $expected = array_map(
            fn (array $r): array => [(string) $r['year'], $r['p10'], $r['p25'], $r['p50'], $r['p75'], $r['p90']],
            $presented['fan']['rows'],
        );

        // The exported figures ARE the panel's figures, row for row — no second derivation.
        $this->assertSame($expected, $rows);
    }

    public function test_the_ladder_csv_reproduces_the_panel_ladder_figures(): void
    {
        [$instance] = $this->completedRun();
        $scenario = $instance->scenario;

        // The component builds the ladder CSV from this same deterministic projection.
        $ladder = ResultPresenter::ladder(app(ScenarioForecaster::class)->deterministic($scenario));

        $csv = $this->capture($instance->downloadLadderCsv());
        $rows = $this->dataRowsAfter($csv, fn (string $line): bool => str_starts_with($line, 'Year,Age(s)'));

        $this->assertCount(count($ladder['rows']), $rows);

        $sourceCount = count($ladder['sources']);
        foreach ($ladder['rows'] as $i => $expected) {
            $cells = $rows[$i];
            $this->assertSame((string) $expected['year'], $cells[0]);

            foreach (array_values($ladder['sources']) as $j => $source) {
                $this->assertSame($expected['income'][$source], $cells[2 + $j], "income {$source} in {$expected['year']}");
            }

            // Tax, Spend, Unmet spend, Usable wealth, Total wealth follow the income columns.
            $this->assertSame($expected['tax'], $cells[2 + $sourceCount]);
            $this->assertSame($expected['spend'], $cells[2 + $sourceCount + 1]);
            $this->assertSame($expected['shortfall'] ?? '', $cells[2 + $sourceCount + 2]);
            $this->assertSame($expected['usableWealth'], $cells[2 + $sourceCount + 3]);
            $this->assertSame($expected['totalWealth'], $cells[2 + $sourceCount + 4]);
        }
    }

    public function test_the_interpretation_quotes_only_percentages_the_panel_shows(): void
    {
        [, $presented, $resultsByVariant] = $this->completedRun();

        $readouts = Interpretation::readouts($resultsByVariant);
        $this->assertNotEmpty($readouts);

        // Every percentage the panel shows, for any variant, gathered as the allowed set.
        $panelPercents = [];
        foreach ($presented['variants'] as $variant) {
            $panelPercents[] = $variant['successEssentials'];
            $panelPercents[] = $variant['successFullSpend'];
            $panelPercents[] = $variant['depletionRate'];
        }

        preg_match_all('/\d+%/', implode(' ', $readouts), $matches);
        $this->assertNotEmpty($matches[0], 'the interpretation should quote concrete percentages');

        foreach ($matches[0] as $token) {
            // If the interpretation formatted a figure differently (e.g. "67.0%") this fails:
            // the figure no longer traces to the one the panel shows.
            $this->assertContains($token, $panelPercents, "interpretation percentage {$token} is not a figure the panel shows");
        }
    }

    public function test_the_pdf_reproduces_the_panel_figures_and_carries_run_provenance(): void
    {
        [$instance, $presented] = $this->completedRun();
        $scenario = $instance->scenario;

        $data = app(ScenarioPdfController::class)->data($scenario);
        $html = view('pdf.results', $data)->render();

        // The PDF's Monte Carlo figures ARE the panel's comparison figures — same presenter,
        // same latest-completed run, so the PDF can't print a run the screen would hide.
        foreach ($presented['comparison']['rows'] as $row) {
            $this->assertStringContainsString($row['successEssentials'], $html);
            $this->assertStringContainsString($row['medianTerminal'], $html);
        }

        // The deterministic ladder figures match the panel's ladder (one projection).
        $ladder = ResultPresenter::ladder(app(ScenarioForecaster::class)->deterministic($scenario));
        $this->assertStringContainsString($ladder['rows'][0]['totalWealth'], $html);

        // The Monte Carlo section carries its run's provenance, so a preview can't pose as
        // the 10k report.
        $this->assertNotNull($data['mcRun']);
        $this->assertStringContainsString((string) $data['mcRun']['seed'], $html);
        $this->assertStringContainsString(number_format($data['mcRun']['paths']), $html);
    }

    /**
     * Run a synchronous preview and return the live component instance, the presenter's
     * panel output, and the per-variant results it was built from.
     *
     * @return array{0: ScenarioResults, 1: array<string, mixed>, 2: Collection<string, Result>}
     */
    private function completedRun(): array
    {
        $component = Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('previewPaths', 30)
            ->call('preview');

        /** @var ScenarioResults $instance */
        $instance = $component->instance();

        $run = SimulationRun::with('results')->findOrFail($component->get('runId'));
        /** @var Collection<string, Result> $resultsByVariant */
        $resultsByVariant = $run->results->keyBy(fn (Result $r): string => $r->variant->value);

        $presented = ResultPresenter::build($resultsByVariant, $instance->scenario->variant->value);

        return [$instance, $presented, $resultsByVariant];
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Parse the CSV data rows that follow the first line matching $isHeader.
     *
     * @param  callable(string): bool  $isHeader
     * @return list<list<string>>
     */
    private function dataRowsAfter(string $csv, callable $isHeader): array
    {
        $lines = explode("\n", str_replace("\r", '', $csv));

        $start = null;
        foreach ($lines as $i => $line) {
            if ($isHeader($line)) {
                $start = $i + 1;
                break;
            }
        }
        $this->assertNotNull($start, 'CSV header row not found');

        $rows = [];
        foreach (array_slice($lines, $start) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }
}
