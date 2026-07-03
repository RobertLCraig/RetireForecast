<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\AssistantService;
use App\Assistant\FigureGrounding;
use PHPUnit\Framework\TestCase;

/**
 * Guardrail G1. The trust-critical property: a figure the model produced that is NOT in the
 * forecast it was given (or in the reader's question) is flagged, so {@see AssistantService}
 * can refuse it. Grounding is strict (no re-rounding) because the tool promises penny-accuracy,
 * but the reader's own numbers and immaterial bare integers are allowed.
 */
final class FigureGroundingTest extends TestCase
{
    public function test_a_currency_figure_present_in_context_is_grounded(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded(
            'You have £154,600.00 of spendable wealth left.',
            'Spendable wealth left at the end: £154,600.00',
            '',
        ));
    }

    public function test_the_same_amount_without_the_pence_is_still_grounded(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded(
            'About £154,600 is left.',
            'Spendable wealth left at the end: £154,600.00',
            '',
        ));
    }

    public function test_an_amount_stated_without_the_pound_sign_is_grounded_by_magnitude(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded(
            'That leaves 154,600 pounds.',
            'Spendable wealth left at the end: £154,600.00',
            '',
        ));
    }

    public function test_an_invented_currency_figure_is_flagged(): void
    {
        $this->assertSame(['£999,999'], FigureGrounding::ungrounded(
            'You have £999,999 left.',
            'Spendable wealth left at the end: £154,600.00',
            '',
        ));
    }

    public function test_a_grounded_calendar_year_passes_and_an_invented_one_is_flagged(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded('It lasts to 2058.', 'Final year of the plan: 2058', ''));
        $this->assertSame(['2099'], FigureGrounding::ungrounded('It lasts to 2099.', 'Final year of the plan: 2058', ''));
    }

    public function test_a_figure_the_reader_supplied_in_the_question_is_grounded(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded(
            'With £200,000 the picture would differ.',
            'Spendable wealth left at the end: £154,600.00',
            'What if I had £200,000 instead?',
        ));
    }

    public function test_percentages_are_grounded_within_tolerance_and_flagged_otherwise(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded('83% of futures fund it.', 'Success: 83%', ''));
        $this->assertSame(['95%'], FigureGrounding::ungrounded('95% of futures fund it.', 'Success: 83%', ''));
    }

    public function test_bare_small_integers_are_not_policed(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded(
            'You have 2 pensions and 1 property.',
            'Spendable wealth left at the end: £154,600.00',
            '',
        ));
    }

    public function test_a_k_suffixed_amount_matches_the_full_magnitude(): void
    {
        $this->assertSame([], FigureGrounding::ungrounded('Around £155k.', 'A cost of £155,000.00', ''));
    }
}
