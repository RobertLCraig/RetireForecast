<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The kind of standalone income stream a person receives outside employment and
 * pensions (e.g. rent from a let property, a purchased annuity).
 *
 * A disability award is TWO cases, not one, because the two components of DLA / AA / PIP are
 * treated differently everywhere it matters (board card 0050): the CARE component
 * ({@see self::DisabilityBenefit}) is assessed as income in a local-authority care financial
 * assessment and stops after 28 days once that authority funds the placement, while the MOBILITY
 * component ({@see self::DisabilityBenefitMobility}) is disregarded in the assessment and runs on
 * for the whole placement. Both are disregarded twice over otherwise: tax-free AND excluded from
 * the Pension Credit income test. The assembler forces both tax-free so neither can be mis-entered
 * as taxable (which would both income-tax it and wrongly dock means-tested benefit).
 *
 * The care case keeps the value `disability_benefit` it has always had, so an award entered before
 * the split reads as care: the adverse reading on both sides (assessed in full for a self-funder,
 * stopped in full for a funded resident).
 */
enum IncomeStreamType: string
{
    case Rental = 'rental';
    case Annuity = 'annuity';
    case DisabilityBenefit = 'disability_benefit';
    case DisabilityBenefitMobility = 'disability_benefit_mobility';
    case Other = 'other';

    /** A tax-free disability benefit (DLA / AA / PIP) is disregarded from income tax and the means test. */
    public function isTaxFreeBenefit(): bool
    {
        return $this === self::DisabilityBenefit || $this === self::DisabilityBenefitMobility;
    }
}
