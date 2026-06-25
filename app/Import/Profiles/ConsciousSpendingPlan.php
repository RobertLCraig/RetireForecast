<?php

declare(strict_types=1);

namespace App\Import\Profiles;

/**
 * Ramit Sethi / "I Will Teach You To Be Rich" Conscious Spending Plan. Its four buckets
 * map cleanly to our model (Fixed Costs -> essential, Guilt-Free -> discretionary,
 * Investments + Savings -> contributions), but the exact monthly cells need a sample to
 * pin, so it stays uncalibrated for now. See docs/PLAN.md "External review triage".
 */
final class ConsciousSpendingPlan extends UncalibratedProfile
{
    public function key(): string
    {
        return 'iwt-csp';
    }

    public function label(): string
    {
        return 'IWT Conscious Spending Plan';
    }

    public function description(): string
    {
        return 'The four-bucket monthly plan (Fixed, Investments, Savings, Guilt-Free). Calibration pending a sample.';
    }
}
