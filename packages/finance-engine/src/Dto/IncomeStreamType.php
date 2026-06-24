<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The kind of standalone income stream a person receives outside employment and
 * pensions (e.g. rent from a let property, a purchased annuity).
 */
enum IncomeStreamType: string
{
    case Rental = 'rental';
    case Annuity = 'annuity';
    case Other = 'other';
}
