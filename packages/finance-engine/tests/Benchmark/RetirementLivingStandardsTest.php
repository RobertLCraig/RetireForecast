<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards;
use RetireForecast\FinanceEngine\Money\Money;

final class RetirementLivingStandardsTest extends TestCase
{
    public function test_tier_figures_match_the_sourced_table(): void
    {
        // Outside London (the default basis): single, then couple.
        $this->assertSame(13_900_00, RetirementLivingStandards::tier('minimum', couple: false, london: false)->pence);
        $this->assertSame(32_700_00, RetirementLivingStandards::tier('moderate', couple: false, london: false)->pence);
        $this->assertSame(45_400_00, RetirementLivingStandards::tier('comfortable', couple: false, london: false)->pence);

        $this->assertSame(22_500_00, RetirementLivingStandards::tier('minimum', couple: true, london: false)->pence);
        $this->assertSame(45_400_00, RetirementLivingStandards::tier('moderate', couple: true, london: false)->pence);
        $this->assertSame(62_700_00, RetirementLivingStandards::tier('comfortable', couple: true, london: false)->pence);
    }

    public function test_london_figures_are_the_higher_cut(): void
    {
        $this->assertSame(14_600_00, RetirementLivingStandards::tier('minimum', couple: false, london: true)->pence);
        $this->assertSame(24_100_00, RetirementLivingStandards::tier('minimum', couple: true, london: true)->pence);
        $this->assertSame(47_000_00, RetirementLivingStandards::tier('moderate', couple: true, london: true)->pence);
        $this->assertSame(64_800_00, RetirementLivingStandards::tier('comfortable', couple: true, london: true)->pence);

        // London is never cheaper than outside London for the same composition.
        foreach (RetirementLivingStandards::TIERS as $tier) {
            foreach ([false, true] as $couple) {
                $this->assertGreaterThan(
                    RetirementLivingStandards::tier($tier, $couple, london: false)->pence,
                    RetirementLivingStandards::tier($tier, $couple, london: true)->pence,
                );
            }
        }
    }

    public function test_classify_reports_the_highest_standard_the_spend_reaches(): void
    {
        // A couple, outside London. Moderate is £45,400, Comfortable £62,700.
        $result = RetirementLivingStandards::classify(Money::fromPounds(50_000), couple: true, london: false);

        $this->assertSame('moderate', $result->tierReached);
        $this->assertTrue($result->meets('minimum'));
        $this->assertTrue($result->meets('moderate'));
        $this->assertFalse($result->meets('comfortable'));
        $this->assertFalse($result->belowMinimum());
    }

    public function test_classify_below_minimum_reaches_no_tier(): void
    {
        $result = RetirementLivingStandards::classify(Money::fromPounds(10_000), couple: false, london: false);

        $this->assertNull($result->tierReached);
        $this->assertTrue($result->belowMinimum());
        $this->assertSame('minimum', $result->nextTier());
        // £13,900 minimum - £10,000 spend = £3,900 to reach the Minimum.
        $this->assertSame(3_900_00, $result->gapToNextTier()->pence);
    }

    public function test_spend_exactly_on_a_tier_boundary_reaches_that_tier(): void
    {
        $result = RetirementLivingStandards::classify(Money::fromPounds(32_700), couple: false, london: false);

        $this->assertSame('moderate', $result->tierReached);
        $this->assertTrue($result->meets('moderate'));
    }

    public function test_next_tier_and_gap_from_the_reached_standard(): void
    {
        // Single, outside London, £35,000: reached Moderate (£32,700); next is Comfortable (£45,400).
        $result = RetirementLivingStandards::classify(Money::fromPounds(35_000), couple: false, london: false);

        $this->assertSame('moderate', $result->tierReached);
        $this->assertSame('comfortable', $result->nextTier());
        $this->assertSame(10_400_00, $result->gapToNextTier()->pence); // £45,400 - £35,000

        // At or above Comfortable there is no next tier.
        $top = RetirementLivingStandards::classify(Money::fromPounds(70_000), couple: false, london: false);
        $this->assertSame('comfortable', $top->tierReached);
        $this->assertNull($top->nextTier());
        $this->assertNull($top->gapToNextTier());
    }

    public function test_carries_its_provenance(): void
    {
        // No magic numbers: the figures travel with a source and a verified-on date.
        $this->assertStringContainsString('retirementlivingstandards.org.uk', RetirementLivingStandards::SOURCE);
        $this->assertSame('2026-06-27', RetirementLivingStandards::VERIFIED_ON);
        $this->assertNotSame('', RetirementLivingStandards::EDITION);
    }
}
