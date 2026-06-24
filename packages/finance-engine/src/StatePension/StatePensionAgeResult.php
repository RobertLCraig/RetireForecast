<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\StatePension;

use DateTimeImmutable;

/**
 * A person's State Pension age, expressed both as years-and-months and as the exact
 * date they reach it (their date of birth plus that period).
 */
final class StatePensionAgeResult
{
    public function __construct(
        public readonly int $years,
        public readonly int $months,
        public readonly DateTimeImmutable $dateReached,
    ) {}
}
