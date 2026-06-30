<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\FigureFreshness;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The verified_on freshness arithmetic, tested against a fixed reference date so it is
 * deterministic (the command supplies the real clock; the logic here never reads it).
 */
final class FigureFreshnessTest extends TestCase
{
    public function test_months_old_counts_whole_months(): void
    {
        $asOf = new DateTimeImmutable('2026-12-27');

        $this->assertSame(6, FigureFreshness::monthsOld('2026-06-27', $asOf));
        $this->assertSame(0, FigureFreshness::monthsOld('2026-12-27', $asOf)); // same day
    }

    public function test_a_future_verification_date_reads_as_zero_not_negative(): void
    {
        $this->assertSame(0, FigureFreshness::monthsOld('2027-01-01', new DateTimeImmutable('2026-12-27')));
    }

    public function test_is_stale_only_past_the_threshold(): void
    {
        // 11 months ≤ 12 → fresh; 13 months > 12 → stale.
        $this->assertFalse(FigureFreshness::isStale('2026-06-27', new DateTimeImmutable('2027-06-01'), 12));
        $this->assertTrue(FigureFreshness::isStale('2026-06-27', new DateTimeImmutable('2027-08-01'), 12));
    }
}
