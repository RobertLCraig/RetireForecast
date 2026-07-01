<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * The kind of standalone income stream a person receives outside employment and
 * pensions (e.g. rent from a let property, a purchased annuity).
 *
 * {@see self::DisabilityBenefit} is a distinct kind because it is disregarded twice
 * over: DLA / AA / PIP are tax-free AND excluded from the Pension Credit income test.
 * The assembler forces such a stream tax-free so it can never be mis-entered as taxable
 * (which would both income-tax it and wrongly dock means-tested benefit).
 */
enum IncomeStreamType: string
{
    case Rental = 'rental';
    case Annuity = 'annuity';
    case DisabilityBenefit = 'disability_benefit';
    case Other = 'other';

    /** A tax-free disability benefit (DLA / AA / PIP) is disregarded from income tax and the means test. */
    public function isTaxFreeBenefit(): bool
    {
        return $this === self::DisabilityBenefit;
    }
}
