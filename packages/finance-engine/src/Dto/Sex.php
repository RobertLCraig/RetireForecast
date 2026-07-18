<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Care\CareAssumptions;

/**
 * Biological sex. Drives the two sex-differentiated engine inputs: the ONS mortality
 * table used in the joint-life model, and the lifetime care probability in the Monte
 * Carlo ({@see CareAssumptions::probabilityOfCare()}).
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
}
