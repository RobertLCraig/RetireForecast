<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * The levers the decision-support threshold-finder can sweep on a scenario. A stable string key so
 * a stored threshold, a queued job payload and a UI choice all name the same lever. Each maps to an
 * engine {@see SweepLever} built with the scenario's context in
 * {@see LeverThresholdService::buildLever}.
 */
enum LeverKey: string
{
    case BuyPrice = 'buy_price';
    case RetirementAge = 'retirement_age';
    case EssentialSpend = 'essential_spend';
    case DiscretionarySpend = 'discretionary_spend';
    case SurvivorDbFraction = 'survivor_db_fraction';
    case SurvivorAnnuityFraction = 'survivor_annuity_fraction';
    case PersonLongevity = 'person_longevity';
    case StatePensionDeferral = 'sp_deferral';
    case Care = 'care';

    public function label(): string
    {
        return match ($this) {
            self::BuyPrice => 'How much you spend on a new home',
            self::RetirementAge => 'When you retire',
            self::EssentialSpend => 'Your essential spending',
            self::DiscretionarySpend => 'What you spend on things you choose',
            self::SurvivorDbFraction => "Your DB pension's survivor share",
            self::SurvivorAnnuityFraction => "Your annuity's survivor share",
            // Generic fallbacks; the explorer names the specific person ("How long Alex lives",
            // "How long Alex defers their State Pension"), since these levers are parameterised by
            // which person they target.
            self::PersonLongevity => 'How long one of you lives',
            self::StatePensionDeferral => 'How long one of you defers the State Pension',
            // A binary, not a sweep — whether the late-life care-fee tail is in the picture.
            self::Care => 'Whether care fees are modelled',
        };
    }
}
