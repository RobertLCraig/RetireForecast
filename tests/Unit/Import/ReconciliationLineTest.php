<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Import\ReconciliationLine;
use Tests\TestCase;

class ReconciliationLineTest extends TestCase
{
    public function test_a_line_with_no_independent_figure_reconciles_and_never_mismatches(): void
    {
        $line = new ReconciliationLine('Essential spending', '24600.00');

        $this->assertTrue($line->reconciles());
        $this->assertFalse($line->mismatch());
    }

    public function test_equal_figures_reconcile(): void
    {
        $line = new ReconciliationLine('Essential spending', '24600.00', '24600.00');

        $this->assertTrue($line->reconciles());
        $this->assertFalse($line->mismatch());
    }

    public function test_a_penny_apart_is_a_visible_mismatch(): void
    {
        $line = new ReconciliationLine('Essential spending', '24600.00', '24600.01');

        $this->assertFalse($line->reconciles());
        $this->assertTrue($line->mismatch());
    }

    public function test_equality_is_judged_in_pence_not_string_form(): void
    {
        // Same value, different formatting — must reconcile (formatting can't invent a mismatch).
        $line = new ReconciliationLine('Essential spending', '24600.00', '24600');

        $this->assertTrue($line->reconciles());
        $this->assertFalse($line->mismatch());
    }

    public function test_to_array_bakes_in_the_booleans_for_the_view(): void
    {
        $line = new ReconciliationLine('Essential spending', '24600.00', '30000.00', 'summed from 9 lines');

        $this->assertSame([
            'label' => 'Essential spending',
            'imported' => '24600.00',
            'stated' => '30000.00',
            'detail' => 'summed from 9 lines',
            'reconciles' => false,
            'mismatch' => true,
        ], $line->toArray());
    }
}
