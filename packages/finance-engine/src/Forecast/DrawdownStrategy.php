<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

/**
 * How the household funds a spending shortfall. All three ship, and the optimiser runs every one of
 * them and reports the cheapest (see DECISIONS.md); tax-efficient is the default display.
 *
 *  - TaxEfficient: spend non-pension assets first (cash, then GIA, then ISA) and
 *    leave the pension to grow, drawing it only as a last resort.
 *  - PensionAware: draw taxable pension income earlier (capped at the basic-rate
 *    band to avoid a tax spike) before non-pension assets, to run the pot down
 *    ahead of unused pots entering the IHT estate from April 2027.
 *  - FillBands: "fill the band" - fill each tax-free allowance before a taxed
 *    pound: pension within the personal allowance (0% tax), then GIA gains within
 *    the CGT annual exempt amount (0% CGT), then cash + ISA (tax-free capital),
 *    then pension within the basic-rate band, then the rest. Pension-Credit-aware:
 *    a household on Guarantee Credit draws capital first and leaves the pension
 *    (and the credit) intact, since any pension income claws the credit back
 *    £-for-£. See docs/PLAN-withdrawal-sequencing.md.
 */
enum DrawdownStrategy: string
{
    case TaxEfficient = 'tax_efficient';
    case PensionAware = 'pension_aware';
    case FillBands = 'fill_bands';

    /**
     * The order in force where the reader has not chosen one. THE one home for it: the app's
     * `ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY` reads this rather than repeating the value,
     * so the order the projection runs on, the baseline every saving is measured against and the
     * disclosure that names it cannot drift apart.
     *
     * Spend-savings-first is the historical default and is kept as one, deliberately: nobody has
     * chosen a replacement, and moving it would silently re-rank every stored plan. It is no longer
     * invisible, which is what board card 0075 was raised for — it is disclosed and it is editable.
     */
    public const DEFAULT = self::TaxEfficient;

    /**
     * THE user-facing name of a draw order, so the builder's control, the comparison panel's two
     * tiles, the cheapest-order sentence and the assumed-figure disclosure all name the same order
     * the same way. Written as a phrase that completes "…by X" and "we have run this plan on X".
     */
    public function label(): string
    {
        return match ($this) {
            self::TaxEfficient => 'spending your savings first',
            self::PensionAware => 'drawing your pension first, up to the basic-rate band',
            self::FillBands => 'filling your tax-free allowances first',
        };
    }

    /**
     * What the order actually does, in the reader's own words. The comparison panel closes with
     * this sentence about whichever order sits in its second tile: it used to describe
     * fill-the-bands and nothing else, so the moment the reader picked fill-the-bands as their own
     * order the panel described the wrong one. Here it travels with the order it is true of.
     */
    public function description(): string
    {
        return match ($this) {
            self::TaxEfficient => 'spends your cash, then your general investments, then your ISAs, and leaves '
                .'your pension untouched for as long as it can, so the pot keeps growing.',
            self::PensionAware => 'takes taxable pension income early, stopping at the top of the basic-rate band '
                .'so it never pushes you into higher-rate tax, and runs the pot down before your other savings.',
            self::FillBands => 'draws pension within your personal allowance and realises gains within your '
                .'capital-gains allowance before taxed income; each pension draw is taken so a quarter of it is '
                .'tax-free cash while your lump sum allowance lasts. If you receive Pension Credit it draws your '
                .'savings first, so pension income does not reduce the credit.',
        };
    }
}
