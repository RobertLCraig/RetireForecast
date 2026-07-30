<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use InvalidArgumentException;
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
 *
 * $ongoingContribution is the MEMBER's own regular payment and $employerContribution the
 * employer's. They are kept apart because they behave differently and always have: only the
 * member's is the household's money (the employer's never passes through the household's
 * cashflow and must not be funded from its surplus), and only the member's attracts tax
 * relief. The employer's is paid while the member is actually working, prorated in a
 * part-year, and stops when they do.
 *
 * $reliefMethod says how the scheme gives income-tax relief on the member's contribution
 * ({@see PensionReliefMethod}). Null = relief is not modelled, the pre-2026-07-31 behaviour,
 * kept so a stored scenario does not silently shift — but it is never true of a real UK
 * pension, so it is surfaced as an input-sanity note rather than passing unremarked.
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
        public readonly ?PensionReliefMethod $reliefMethod = null,
    ) {
        // Relief at source is a real method the DTO can express, but the projector does not yet
        // model it (the provider's basic-rate reclaim, and a higher-rate taxpayer's self-assessment
        // recovery landing in a LATER year). Accepting it silently would give NO relief at all
        // while the input said otherwise — worse than refusing it. See PLAN-adviser-parity.md A2.
        if ($reliefMethod === PensionReliefMethod::ReliefAtSource) {
            throw new InvalidArgumentException(
                'Relief at source is not modelled yet: only net pay is. Leave the relief method '
                .'unset to keep the un-relieved behaviour, which is disclosed as an input note.'
            );
        }
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function type(): PensionType
    {
        return PensionType::DefinedContribution;
    }

    /**
     * The same pot with a different annuity plan (immutable; e.g. a sweep lever varying the
     * joint-life survivor fraction). Everything else is preserved.
     */
    public function withAnnuityPurchase(?AnnuityPurchase $annuityPurchase): self
    {
        return new self(
            $this->ownerId,
            $this->currentValue,
            $this->ongoingContribution,
            $this->employerContribution,
            $this->earliestAccessAge,
            $this->withdrawalPlan,
            $this->pclsTakenToDate,
            $this->growthAssumptionOverride,
            $annuityPurchase,
            $this->reliefMethod,
        );
    }
}
