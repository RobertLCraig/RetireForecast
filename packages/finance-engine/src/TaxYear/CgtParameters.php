<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Tax\ChattelsGain;

/**
 * Capital Gains Tax parameters for residential property, for one tax year.
 *
 * A main home is normally fully relieved by Private Residence Relief, so the couple
 * pay no CGT on selling it; these figures matter for the edges (a property that was
 * let or was not the main home throughout) and for gains on a General Investment
 * Account.
 *
 * Verified against gov.uk/capital-gains-tax/rates on 2026-06-27: residential gains are
 * 18% within the basic-rate band and 24% above it, the annual exempt amount is £3,000,
 * and the final 9 months of ownership always qualify for Private Residence Relief (HS283).
 *
 * The two deemed-occupation caps are the statutory periods of absence that still count as
 * living there (TCGA 1992 s223(3), set out on gov.uk HS283 /tax-sell-home/absence-from-home):
 * absences of up to 3 years in total for ANY reason, and up to 4 years in total where a job
 * kept you living elsewhere in the UK. Working abroad has no cap, so it needs no parameter.
 * These are fixed statute, not annually uprated figures, so both tax years carry the same
 * numbers; they ride the CGT block's 2026-06-27 HS283 verification.
 *
 * $chattelsExemptAmount is the per-item (or per-set) threshold below which selling personal
 * possessions is not chargeable at all, and above which the gain is capped at five thirds of the
 * excess ({@see ChattelsGain}). It is fixed statute at £6,000 and has not been uprated since 1989,
 * so both tax years carry the same figure. Board card 0065.
 */
final class CgtParameters
{
    public function __construct(
        public readonly Money $annualExemptAmount,
        public readonly Percent $residentialBasicRate,
        public readonly Percent $residentialHigherRate,
        public readonly int $privateResidenceFinalExemptionMonths,
        public readonly int $deemedOccupationAnyReasonMonths,
        public readonly int $deemedOccupationWorkElsewhereUkMonths,
        public readonly Money $chattelsExemptAmount,
    ) {}
}
