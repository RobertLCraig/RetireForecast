<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Tax\ChattelsGain;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0065, criterion #3. Selling personal possessions (art, jewellery, antiques) is a
 * chargeable disposal above a threshold per item or set, and the model charged nothing at all: a
 * plan that turns on selling the paintings was optimistic by the whole tax bill.
 *
 * The rule is TCGA 1992 s262: nothing is chargeable while the proceeds stay inside the exempt
 * amount, and just above it the gain is capped at five thirds of the excess, so the charge phases
 * in instead of jumping off a cliff.
 */
final class ChattelsGainTest extends TestCase
{
    private function exemptAmount(): Money
    {
        return TaxYearRegistry::for('2026-27')->cgt->chattelsExemptAmount;
    }

    public function test_a_sale_inside_the_exempt_amount_is_not_chargeable_at_all(): void
    {
        // £5,000 for something that cost £1,000: a real £4,000 gain, and no tax on any of it.
        $this->assertSame(0, ChattelsGain::chargeableGain(
            Money::fromPounds(5_000), Money::fromPounds(1_000), $this->exemptAmount(),
        )->pence);
    }

    public function test_just_above_the_threshold_the_gain_is_capped_at_five_thirds_of_the_excess(): void
    {
        // £7,000 for something that cost £1,000. The real gain is £6,000, but the marginal relief
        // caps it at 5/3 x (£7,000 - £6,000) = £1,666.67, which is what stops a pound over the
        // threshold costing thousands.
        $this->assertSame(166_667, ChattelsGain::chargeableGain(
            Money::fromPounds(7_000), Money::fromPounds(1_000), $this->exemptAmount(),
        )->pence);
    }

    public function test_a_large_sale_is_charged_on_its_actual_gain(): void
    {
        // £20,000 for something that cost £1,000. The 5/3 cap is £23,333.33, well above the real
        // £19,000 gain, so the relief has stopped biting and the ordinary computation applies.
        $this->assertSame(1_900_000, ChattelsGain::chargeableGain(
            Money::fromPounds(20_000), Money::fromPounds(1_000), $this->exemptAmount(),
        )->pence);
    }

    public function test_the_cap_never_makes_the_gain_bigger_than_it_really_was(): void
    {
        // £7,000 for something that cost £6,500: the real gain is £500, under the £1,666.67 cap.
        $this->assertSame(50_000, ChattelsGain::chargeableGain(
            Money::fromPounds(7_000), Money::fromPounds(6_500), $this->exemptAmount(),
        )->pence);
    }

    public function test_selling_at_a_loss_is_charged_nothing(): void
    {
        $this->assertSame(0, ChattelsGain::chargeableGain(
            Money::fromPounds(20_000), Money::fromPounds(25_000), $this->exemptAmount(),
        )->pence);
    }
}
