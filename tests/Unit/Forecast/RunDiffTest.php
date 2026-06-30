<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;

/**
 * "Since your last run": ResultPresenter::runDiff turns two completed-run snapshots into the
 * headline figures that moved, with a better/worse direction (higher success + end wealth are
 * better; a later — or "never" — run-short year is better). Figures that did not change are
 * omitted, so the panel shows only what actually moved.
 */
final class RunDiffTest extends TestCase
{
    public function test_it_lists_only_figures_that_changed_with_the_right_direction(): void
    {
        $previous = ['successEssentials' => 0.80, 'successFullSpend' => 0.50, 'endWealthPence' => 1_000_000, 'medianDepletionYear' => 2044];
        $current = ['successEssentials' => 0.88, 'successFullSpend' => 0.50, 'endWealthPence' => 1_200_000, 'medianDepletionYear' => null];

        $byLabel = array_column(ResultPresenter::runDiff($current, $previous), null, 'label');

        // Success-of-essentials rose → better; the unchanged full-spend chance is omitted.
        $this->assertTrue($byLabel['Chance essentials are always met']['better']);
        $this->assertArrayNotHasKey('Chance the full budget is always met', $byLabel);

        // End wealth rose → better.
        $this->assertTrue($byLabel['Spendable wealth at the end (median)']['better']);

        // The money now never runs short (was 2044) → better, shown as "never".
        $this->assertSame('2044', $byLabel['Median year the money runs short']['from']);
        $this->assertSame('never', $byLabel['Median year the money runs short']['to']);
        $this->assertTrue($byLabel['Median year the money runs short']['better']);
    }

    public function test_a_worse_result_is_flagged_red(): void
    {
        $previous = ['successEssentials' => 0.90, 'successFullSpend' => 0.90, 'endWealthPence' => 2_000_000, 'medianDepletionYear' => null];
        $current = ['successEssentials' => 0.70, 'successFullSpend' => 0.90, 'endWealthPence' => 1_500_000, 'medianDepletionYear' => 2050];

        $byLabel = array_column(ResultPresenter::runDiff($current, $previous), null, 'label');

        $this->assertFalse($byLabel['Chance essentials are always met']['better']);
        $this->assertFalse($byLabel['Spendable wealth at the end (median)']['better']);
        // The money now runs short in 2050 (was never) → worse.
        $this->assertSame('never', $byLabel['Median year the money runs short']['from']);
        $this->assertFalse($byLabel['Median year the money runs short']['better']);
    }

    public function test_identical_snapshots_produce_no_rows(): void
    {
        $snapshot = ['successEssentials' => 0.85, 'successFullSpend' => 0.60, 'endWealthPence' => 1_000_000, 'medianDepletionYear' => 2048];

        $this->assertSame([], ResultPresenter::runDiff($snapshot, $snapshot));
    }
}
