<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of drawing a lump from the household's liquid savings (see
 * {@see SavingsFunding::draw}): the accounts rebuilt with the drawn amounts
 * removed, the total actually drawn (capped at what was available), and the
 * GIA gains the draw realised per person (a GIA disposal realises the
 * pro-rata slice of its unrealised gain, which is chargeable to CGT).
 */
final class SavingsFundingResult
{
    /**
     * @param  list<Account>  $accounts  every account of the household, in the original
     *                                   order, with drawn balances reduced (drained rows kept at £0)
     * @param  array<string, Money>  $realisedGains  personId => GIA gain realised by the draw
     */
    public function __construct(
        public readonly array $accounts,
        public readonly Money $drawn,
        public readonly array $realisedGains,
    ) {}
}
