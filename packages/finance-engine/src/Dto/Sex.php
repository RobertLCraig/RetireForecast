<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Biological sex, used only to select the correct ONS mortality table. Kept
 * separate from any other concept because it exists purely for the life-table
 * lookup in the joint-life mortality model.
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
}
