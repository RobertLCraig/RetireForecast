<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * A per-person adjustment to modelled lifespan, feeding the joint-life mortality
 * sampler (Monte Carlo) and the representative death age (deterministic forecast).
 *
 * $value is interpreted by $mode: the assumed age for {@see LongevityMode::FixedAge},
 * the ± year offset for {@see LongevityMode::OffsetYears}, and the q(x) multiplier
 * for {@see LongevityMode::MortalityMultiplier}. It is ignored for
 * {@see LongevityMode::Peer}. Never used in any tax/cashflow arithmetic — it only
 * affects when the household (or first death) occurs.
 */
final class LongevityAdjustment
{
    public function __construct(
        public readonly LongevityMode $mode,
        public readonly float $value = 0.0,
    ) {}

    public static function peer(): self
    {
        return new self(LongevityMode::Peer);
    }

    public static function fixedAge(int $age): self
    {
        return new self(LongevityMode::FixedAge, (float) $age);
    }

    public static function offsetYears(int $years): self
    {
        return new self(LongevityMode::OffsetYears, (float) $years);
    }

    public static function mortalityMultiplier(float $multiplier): self
    {
        return new self(LongevityMode::MortalityMultiplier, $multiplier);
    }
}
