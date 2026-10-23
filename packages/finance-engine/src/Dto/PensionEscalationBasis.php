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
 * payment when prices fall — so a capped basis never returns a negative increase. Plain CPI and
 * RPI are floored the same way (card 0170).
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
     * 39 bp: the OBR's long-run RPI-minus-CPI gap (long-run RPI 2.3855% against CPI 2.0%, "in line
     * with CPIH"). RPI takes CPIH's methods and data from February 2030 (HM Treasury / UK Statistics
     * Authority response, 25 November 2020), so after that RPI follows CPIH, not CPI, and the wedge
     * narrows but stays positive.
     *
     * One flat figure, not the OBR's year-by-year path: {@see increase} takes no year. For a plan
     * starting 2026-27 the flat 39 bp compounds to no more than that path at every horizon, because
     * it gives up the pre-2030 wedge of about 0.8-1.0 pp. That is the cautious direction for income
     * the household receives. Board card 0095; docs/research/RESEARCH-0095-db-escalation-figures.md.
     *
     * Source: https://obr.uk/download/long-term-economic-determinants-march-2026-economic-and-fiscal-outlook/
     * and https://obr.uk/box/the-long-run-difference-between-rpi-and-cpi-inflation/ ;
     * alignment: https://assets.publishing.service.gov.uk/media/5fbd3b86d3bf7f5735e29b43/RPI_Response_FINAL_VERSION_.pdf
     * verified_on: 2026-10-11
     */
    public const RPI_OVER_CPI_WEDGE_BPS = 39;

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
            // Floored: a CPI- or RPI-linked pension is frozen when prices fall, never cut (card 0170,
            // ASSUMPTIONS §18: public service pensions were frozen from April 2016 after CPI of -0.1%).
            self::Cpi => max(0.0, $inflation),
            self::Rpi => max(0.0, $inflation + self::RPI_OVER_CPI_WEDGE_BPS / 10_000),
            self::Fixed => $fixedRate,
            // Capped AND floored: a scheme does not cut a pension in payment when prices fall.
            self::CpiCappedAt5, self::CpiCappedAt2_5 => max(0.0, min($inflation, (float) $this->capBasisPoints() / 10_000)),
        };
    }
}
