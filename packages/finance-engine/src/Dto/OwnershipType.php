<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Whether a property is owned outright or still has a mortgage. A remaining
 * mortgage reduces the net proceeds available on sale.
 */
enum OwnershipType: string
{
    case Outright = 'outright';
    case Mortgaged = 'mortgaged';
}
