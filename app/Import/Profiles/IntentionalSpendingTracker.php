<?php

declare(strict_types=1);

namespace App\Import\Profiles;

/**
 * Nischa's Intentional Spending Tracker — a three-bucket monthly tracker (Fundamentals ->
 * essential, Fun -> discretionary, Future -> savings/contributions). The published sheet
 * is email-gated, so its exact layout needs a sample export to map; uncalibrated until
 * then. See docs/PLAN.md "External review triage".
 */
final class IntentionalSpendingTracker extends UncalibratedProfile
{
    public function key(): string
    {
        return 'nischa-ist';
    }

    public function label(): string
    {
        return 'Nischa Intentional Spending Tracker';
    }

    public function description(): string
    {
        return 'The three-bucket monthly tracker (Fundamentals, Fun, Future). Calibration pending a sample.';
    }
}
