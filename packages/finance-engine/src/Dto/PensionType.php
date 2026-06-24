<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The kind of pension. Each maps to a different DTO with the fields it needs:
 * a money-purchase pot (DC), a guaranteed annual income (DB), or the State Pension.
 */
enum PensionType: string
{
    case DefinedContribution = 'dc';
    case DefinedBenefit = 'db';
    case State = 'state';
}
