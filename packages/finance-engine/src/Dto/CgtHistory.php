<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The Capital Gains Tax history of a property, needed to work out the tax on selling it
 * when Private Residence Relief is only partial (it was let, or not the main home, for
 * part of ownership). Null on a {@see Property} means the common full-relief case — main
 * home throughout, no CGT.
 *
 * What drives the relief is OCCUPATION, not the mortgage: a period lived in as the only or
 * main home is relieved even if the property was on a buy-to-let mortgage at the time
 * (gov.uk HS283 looks at actual residence). So this records the months it was the main
 * residence against the total months owned, plus the costs that reduce the gain.
 *
 *   gain      = sale price − purchase price − improvement/acquisition costs − selling costs
 *   relief    = gain × (mainResidenceMonths + final 9 months) ÷ ownershipMonths
 *   chargeable= gain − relief, then each owner's £3,000 allowance and rate
 *
 * $owners is the number of individuals the gain is split across (1, or 2 for a jointly-owned
 * home): CGT is a per-person tax, so each owner gets their own annual exempt amount and rate.
 */
final class CgtHistory
{
    public function __construct(
        public readonly Money $purchasePrice,
        public readonly Money $improvementCosts,
        public readonly int $ownershipMonths,
        public readonly int $mainResidenceMonths,
        public readonly bool $higherRateOnSale = false,
        public readonly int $owners = 1,
    ) {}
}
