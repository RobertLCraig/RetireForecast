<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Common interface for the three pension DTOs so a household can hold a mixed list
 * of them. Each pension belongs to one person (by id) and knows its type.
 */
interface Pension
{
    public function ownerId(): string;

    public function type(): PensionType;
}
