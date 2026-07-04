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

    public function label(): string
    {
        return match ($this) {
            self::BuyPrice => 'How much you spend on a new home',
            self::RetirementAge => 'When you retire',
            self::EssentialSpend => 'Your essential spending',
        };
    }
}
