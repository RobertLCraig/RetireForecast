<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A Defined Contribution (money-purchase) pension: a pot that grows with
 * investment returns and contributions, accessible flexibly from the earliest
 * access age. $pclsTakenToDate tracks Lump Sum Allowance use across all pensions
 * (null = none taken yet).
 *
 * $annuityPurchase (null = keep in drawdown) converts part of the pot into a lifetime
 * annuity income at a chosen age — see {@see AnnuityPurchase}.
 */
final class DcPension implements Pension
{
    /**
     * @param  list<WithdrawalInstruction>  $withdrawalPlan
     */
    public function __construct(
        public readonly string $ownerId,
        public readonly Money $currentValue,
        public readonly Money $ongoingContribution,
        public readonly Money $employerContribution,
        public readonly int $earliestAccessAge,
        public readonly array $withdrawalPlan = [],
        public readonly ?Money $pclsTakenToDate = null,
        public readonly ?Percent $growthAssumptionOverride = null,
        public readonly ?AnnuityPurchase $annuityPurchase = null,
    ) {}

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function type(): PensionType
    {
        return PensionType::DefinedContribution;
    }
}
