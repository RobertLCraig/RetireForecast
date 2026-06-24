<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Property;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Stamp Duty Land Tax on a residential purchase (England and Northern Ireland).
 *
 * SDLT is progressive: each slice of the price is charged at its band's rate. If
 * the buyer owns another property at completion (e.g. they buy the cheaper home
 * before selling the old one), a surcharge applies to the whole price — but it is
 * reclaimable once the previous main residence sells within 36 months, which the
 * result records separately so the model can treat it as temporary.
 */
final class SdltCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function compute(Money $price, bool $additionalProperty = false): SdltResult
    {
        $bands = $this->config->sdlt->bands;
        $count = count($bands);

        $baseTax = Money::zero();
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $lower = $bands[$i]->threshold;
            $upper = $i + 1 < $count ? $bands[$i + 1]->threshold : null;

            $portion = $upper === null
                ? $price->minus($lower)->minZero()
                : Money::min($price, $upper)->minus($lower)->minZero();

            if (! $portion->isPositive()) {
                continue;
            }

            $tax = $portion->applyRate($bands[$i]->rate);
            $baseTax = $baseTax->plus($tax);
            $lines[] = ['rate' => $bands[$i]->rate, 'amount' => $portion, 'tax' => $tax];
        }

        $surcharge = $additionalProperty
            ? $price->applyRate($this->config->sdlt->additionalPropertySurchargeRate)
            : Money::zero();

        return new SdltResult(
            total: $baseTax->plus($surcharge),
            baseTax: $baseTax,
            surcharge: $surcharge,
            reclaimableSurcharge: $surcharge,
            additionalProperty: $additionalProperty,
            bands: $lines,
        );
    }
}
