<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A plan to buy a lifetime annuity: at $atAge, $amount is taken from the asset this purchase
 * hangs off and buys an annuity paying $amount × the effective rate a year, for life.
 *
 * It hangs off EITHER a {@see DcPension} (a pension annuity, funded from that member's pots) or
 * an {@see Account} (a PURCHASED LIFE ANNUITY, funded from cash, an ISA or a general investment
 * account). The two are the same contract with different tax: pension-annuity income is taxable
 * in full, while a purchased life annuity is part return of the buyer's own capital, and only the
 * interest element is taxed ({@see PathProjector} applies the split). Board card 0060: before it,
 * an annuity could only be bought from a pension pot, so a household whose money sat outside a
 * pension could not buy secured income for a survivor at all.
 *
 * The pot falls by the purchase amount (it becomes an income, not a capital sum — like a
 * DB pension, it holds no drawable value afterwards), and the income is taxed as it is
 * received, so buying the annuity is not itself a taxable event.
 *
 * $escalation === None is a LEVEL annuity: a flat nominal income that falls in real terms.
 * Any other basis escalates the income with inflation from purchase (an RPI/CPI annuity,
 * roughly flat in real terms), mirroring how the engine escalates DB pensions in payment.
 *
 * $survivorFraction (null = single life) is the fraction of the income that continues to
 * the surviving partner after the annuitant dies — a joint-life annuity.
 *
 * $rate is a user input (defaulted from a sourced market quote in the UI), so no fabricated
 * age/rate table is baked into the engine — the engine only multiplies the pot by the rate.
 */
final class AnnuityPurchase
{
    /**
     * How much more an ENHANCED (impaired-life) annuity pays than an ordinary one at the same
     * price, where the reader marks the buyer's health as impaired but gives no quote of their own.
     * Insurers price a shortened life expectancy, and the uplift ranges from a few per cent for a
     * mild condition to roughly a third for a serious one; 10% is the CAUTIOUS end of that range,
     * chosen because over-stating secured income is the failure this tool must not make. It is an
     * industry range, STATED not verified: see docs/spec/ASSUMPTIONS.md §32, board card 0060.
     * Disclosed by `ResultPresenter::assumedFigures()`, which READS this constant.
     */
    public const ENHANCED_UPLIFT_BPS = 1000;

    public function __construct(
        public readonly int $atAge,
        public readonly Money $amount,
        public readonly Percent $rate,
        public readonly PensionEscalationBasis $escalation = PensionEscalationBasis::None,
        public readonly ?Percent $survivorFraction = null,
        // A DEFERRED annuity: the money is handed over at $atAge and the income starts at this
        // (later) age. Null = income starts at purchase. The rate is the reader's own, so nothing
        // is invented for the wait — a real deferred quote pays MORE, so enter the quoted rate.
        public readonly ?int $incomeFromAge = null,
        // An ENHANCED (impaired-life) annuity: the buyer's health shortens their expected life, so
        // the same money buys a bigger income. Applies ENHANCED_UPLIFT_BPS to the rate.
        public readonly bool $enhanced = false,
    ) {}

    /**
     * The rate the income is actually bought at: the quoted rate, uplifted where the annuity is
     * marked enhanced. One home for the uplift, so the projector and any disclosure agree.
     */
    public function effectiveRate(): Percent
    {
        if (! $this->enhanced) {
            return $this->rate;
        }

        return Percent::fromBasisPoints(
            (int) round($this->rate->basisPoints * (1 + self::ENHANCED_UPLIFT_BPS / 10_000))
        );
    }

    /** The age the income starts: the deferred age where one is given, else the purchase age. */
    public function incomeStartAge(): int
    {
        return max($this->incomeFromAge ?? $this->atAge, $this->atAge);
    }

    /**
     * The same annuity with a different survivor's fraction (immutable; e.g. a sweep lever exploring
     * how much of the income should carry on to the surviving partner). Everything else is preserved.
     */
    public function withSurvivorFraction(?Percent $survivorFraction): self
    {
        return new self(
            $this->atAge,
            $this->amount,
            $this->rate,
            $this->escalation,
            $survivorFraction,
            $this->incomeFromAge,
            $this->enhanced,
        );
    }
}
