<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * How a Defined Benefit pension increases. Used both for revaluation before it
 * comes into payment and for escalation once in payment (the two can differ, and
 * the projector applies each in its own phase — see {@see DbPension}).
 *
 * The percentage a basis grants is a function of the year's CPI, so the basis names
 * the RULE and {@see increase} applies it. The two capped cases are the statutory
 * "limited price indexation" ladder: 5% for pensionable service before 6 April 2005
 * and 2.5% for service after it (Pensions Act 1995 s.51 as amended by Pensions Act
 * 2004 s.278). LPI is a floor as well as a cap — a scheme does not cut a pension in
 * payment when prices fall — so a capped basis never returns a negative increase.
 */
enum PensionEscalationBasis: string
{
    case None = 'none';
    case Cpi = 'cpi';
    case Rpi = 'rpi';
    case CpiCappedAt5 = 'cpi_capped_5';
    case CpiCappedAt2_5 = 'cpi_capped_2_5';
    case Fixed = 'fixed';

    /**
     * How far RPI is modelled as running above CPI, in basis points.
     *
     * ZERO, deliberately, and this is the one figure in this file with no citation. RPI is being
     * aligned with CPIH from February 2030 (UK Statistics Authority / HM Treasury, announced
     * November 2020), and a retirement projection started now spends nearly all of its years on
     * the far side of that date — so a permanent wedge would overstate an RPI-linked pension for
     * the bulk of the plan. Zero is also the ADVERSE reading for income the household receives,
     * which is the house rule where several figures are plausible.
     *
     * The reasoning above could not be verified against a primary source in the session that wrote
     * it (no web access), and the pre-2030 years genuinely do carry a wedge. Board card 0095 holds
     * the sourcing. Until it lands, the app's assumed-figure disclosures tell any reader who picks
     * RPI that the model treats it as CPI, so the choice is not silently ignored.
     */
    public const RPI_OVER_CPI_WEDGE_BPS = 0;

    /**
     * The statutory cap on this basis in basis points, or null when the basis is not capped.
     * The single home for the two LPI ceilings: the projector, the disclosure and the builder
     * copy all read it rather than restating 5 and 2.5.
     */
    public function capBasisPoints(): ?int
    {
        return match ($this) {
            self::CpiCappedAt5 => 500,
            self::CpiCappedAt2_5 => 250,
            default => null,
        };
    }

    /**
     * How this basis is described to a reader. Lives beside the rule so a select cannot offer a
     * basis the engine does not model, and a capped case READS its own cap rather than restating
     * it in a template.
     */
    public function label(): string
    {
        $cap = $this->capBasisPoints();
        if ($cap !== null) {
            return 'CPI capped at '.rtrim(rtrim(number_format($cap / 100, 1), '0'), '.').'%';
        }

        return match ($this) {
            self::None => 'No increases',
            self::Cpi => 'CPI (inflation)',
            self::Rpi => 'RPI',
            self::Fixed => 'Fixed rate',
        };
    }

    /**
     * This year's increase as a fraction, given the year's CPI and the fixed rate the scheme
     * grants (only read by {@see self::Fixed}; every other case ignores it).
     */
    public function increase(float $inflation, float $fixedRate): float
    {
        return match ($this) {
            self::None => 0.0,
            self::Cpi => $inflation,
            self::Rpi => $inflation + self::RPI_OVER_CPI_WEDGE_BPS / 10_000,
            self::Fixed => $fixedRate,
            // Capped AND floored: a scheme does not cut a pension in payment when prices fall.
            self::CpiCappedAt5, self::CpiCappedAt2_5 => max(0.0, min($inflation, (float) $this->capBasisPoints() / 10_000)),
        };
    }
}
