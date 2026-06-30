<?php

declare(strict_types=1);

namespace App\Finance;

use App\Console\Commands\CheckFigureFreshness;
use DateTimeImmutable;

/**
 * How long ago each tax-year configuration's statutory figures were last verified against
 * gov.uk (the `verified_on` discipline — see CLAUDE.md "Every tax figure carries a source
 * URL and a verified_on date"). Pure date arithmetic so it is testable without the clock;
 * the {@see CheckFigureFreshness} command supplies "now".
 *
 * A figure going stale is not wrong, but it is a prompt to re-run the gov.uk verification
 * pass before relying on it — so this is the guardrail that turns "we verified once" into
 * "we notice when that verification ages".
 */
final class FigureFreshness
{
    /** Whole months between the verification date and $asOf (0 if the date is in the future). */
    public static function monthsOld(string $verifiedOn, DateTimeImmutable $asOf): int
    {
        $verified = new DateTimeImmutable($verifiedOn);
        if ($verified >= $asOf) {
            return 0;
        }

        $diff = $verified->diff($asOf);

        return $diff->y * 12 + $diff->m;
    }

    /** True when the figures were verified more than $thresholdMonths ago. */
    public static function isStale(string $verifiedOn, DateTimeImmutable $asOf, int $thresholdMonths): bool
    {
        return self::monthsOld($verifiedOn, $asOf) > $thresholdMonths;
    }
}
