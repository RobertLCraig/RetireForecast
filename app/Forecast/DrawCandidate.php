<?php

declare(strict_types=1);

namespace App\Forecast;

use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * ONE order the bounded search ({@see WithdrawalStrategyComparison}) prices: a named
 * {@see DrawdownStrategy}, optionally held under a taxable-income TARGET.
 *
 * The target is what makes the search a search rather than a menu (board card 0078). The three
 * named orders are the only ones anybody has written down, so before this the "cheapest of the
 * orders we tried" could not be an order nobody had named. A target candidate is generated: it
 * fills the pension up to £X of taxable income instead of up to a fixed statutory band, and X is
 * varied across a small set of values, so an order the tool holds no name for can win.
 *
 * It stops well short of a general planner, which Rob's decision 1 of 2026-07-01 rules out: the
 * target rides the ForecastSettings the projector already reads, and the ORDER of the draw is
 * still the fill-the-bands order the engine owns. Nothing here decides how to draw; it only
 * decides where the pension pass stops.
 */
final class DrawCandidate
{
    private function __construct(
        public readonly DrawdownStrategy $strategy,
        /** Taxable income (pence, base-year) the pension pass is filled up to. Null = the order's own bands. */
        public readonly ?int $taxableIncomeTargetPence,
    ) {}

    /** One of the orders the tool has a name for, run exactly as the reader can pick it. */
    public static function order(DrawdownStrategy $strategy): self
    {
        return new self($strategy, null);
    }

    /**
     * A GENERATED order: fill the tax-free allowances, but stop the pension draw at $pence of
     * taxable income rather than at the personal allowance and then the basic-rate ceiling.
     * Built on FillBands because that order already owns the "fill to a limit, then take capital"
     * machinery and the Pension-Credit-aware exception to it.
     */
    public static function managingTaxableIncomeTo(int $pence): self
    {
        return new self(DrawdownStrategy::FillBands, $pence);
    }

    /** Whether this order is generated rather than one of the ones the tool has a name for. */
    public function isGenerated(): bool
    {
        return $this->taxableIncomeTargetPence !== null;
    }

    /** A key unique across the candidate set, so two candidates cannot share a lifetime-tax total. */
    public function key(): string
    {
        return $this->strategy->name.($this->taxableIncomeTargetPence === null ? '' : ':'.$this->taxableIncomeTargetPence);
    }

    /**
     * The order's user-facing NAME, a phrase completing "the cheapest is X". A generated order has
     * to name the thing the reader would actually do and the figure it turns on, never the internal
     * setting that carries it (board card 0078, criterion 3): "the target" or a strategy key tells
     * a reader nothing they can act on, while the amount of taxable income to stay under is the
     * whole instruction.
     */
    public function label(): string
    {
        if ($this->taxableIncomeTargetPence === null) {
            return $this->strategy->label();
        }

        return 'keeping each person\'s taxable income under '
            .Money::fromPence($this->taxableIncomeTargetPence)->format()
            .' a year';
    }
}
