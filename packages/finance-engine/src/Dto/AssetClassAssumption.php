<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The return assumption for one asset class: its expected real (above-inflation)
 * return and its volatility (annual standard deviation of returns). Both are
 * expressed as percentages; the Monte Carlo converts them to fractions when
 * generating paths.
 *
 * Each of the two figures carries its OWN source and verified-on date (board card 0062).
 * They are sourced apart because they are sourced apart in fact: the shipped default takes
 * its returns from the FCA's projection rates and its volatilities from the long-run
 * historical record, so one citation covering both would be wrong about one of them. These
 * are the two figures that decide the answer more than any other in the engine, and until
 * this card the only thing recording where they came from was a prose note on the whole SET.
 *
 * All four are NULLABLE: a test fixture and a snapshot stored before the card exist and
 * build an asset class without them, and reading them as "not stated" is honest where
 * inventing a citation would not be. What is not optional is the SHIPPED library stating
 * them, which the engine's AssetClassSourcingTest demands of every class in every shipped set.
 */
final class AssetClassAssumption
{
    public function __construct(
        public readonly string $name,
        public readonly Percent $expectedRealReturn,
        public readonly Percent $volatility,
        public readonly ?string $returnSource = null,
        public readonly ?string $returnVerifiedOn = null,
        public readonly ?string $volatilitySource = null,
        public readonly ?string $volatilityVerifiedOn = null,
    ) {}
}
