<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Property;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The Stamp Duty Land Tax due on buying the cheaper home: the progressive band
 * charge, any additional-property surcharge, and how much of that surcharge is
 * reclaimable.
 *
 * $reclaimableSurcharge is non-zero when the surcharge was paid only because the
 * old main home had not yet sold (buying before selling). HMRC refunds it once the
 * previous main residence is sold within 36 months, so the buy-vs-rent model treats
 * it as a temporary cost, not a permanent one.
 */
final class SdltResult
{
    /**
     * @param  list<array{rate: Percent, amount: Money, tax: Money}>  $bands
     */
    public function __construct(
        public readonly Money $total,
        public readonly Money $baseTax,
        public readonly Money $surcharge,
        public readonly Money $reclaimableSurcharge,
        public readonly bool $additionalProperty,
        public readonly array $bands,
    ) {}
}
