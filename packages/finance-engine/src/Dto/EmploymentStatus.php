<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * A person's employment status, which decides whether earnings and National
 * Insurance apply in a given year.
 */
enum EmploymentStatus: string
{
    case Employed = 'employed';
    case SelfEmployed = 'self_employed';
    case Retired = 'retired';
    case NotWorking = 'not_working';
}
