<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * How a Defined Benefit pension increases. Used both for revaluation before it
 * comes into payment and for escalation once in payment (the two can differ).
 *
 * The actual percentages are supplied by the AssumptionSet (e.g. CPI/RPI
 * projections), so the basis names the rule rather than a fixed number.
 */
enum PensionEscalationBasis: string
{
    case None = 'none';
    case Cpi = 'cpi';
    case Rpi = 'rpi';
    case CpiCappedAt5 = 'cpi_capped_5';
    case Fixed = 'fixed';
}
