<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

/**
 * The named asset mixes a reader can pick from (board card 0062). Before this card the mix
 * was a hardcoded cautious 40/60 that no screen could move, which made the largest single
 * determinant of the whole answer the one input the household did not have.
 *
 * Four points on one axis — how much of the invested money is in shares — because that is
 * the axis that decides both the return and the risk, and a reader choosing between four
 * labelled mixes is making a judgement they can defend, where a reader typing a return is
 * making one they cannot. Cash is 0 in every profile: a cash HOLDING is entered as a cash
 * account and modelled at the cash assumption, so putting cash inside the invested mix as
 * well would count the same caution twice.
 *
 * {@see Cautious} is the default and is byte-identical to the 40/60 the engine has always
 * used, so a scenario stored before this card reproduces exactly.
 */
enum AllocationProfile: string
{
    case Defensive = 'defensive';
    case Cautious = 'cautious';
    case Balanced = 'balanced';
    case Growth = 'growth';

    /** The mix every reader who says nothing gets, and what every stored scenario ran on. */
    public const DEFAULT = self::Cautious;

    /** The share of the invested money held in equities; the rest is in bonds. */
    public function equityWeight(): float
    {
        return match ($this) {
            self::Defensive => 0.20,
            self::Cautious => 0.40,
            self::Balanced => 0.60,
            self::Growth => 0.80,
        };
    }

    public function allocation(): PortfolioAllocation
    {
        return new PortfolioAllocation([$this->equityWeight(), 1.0 - $this->equityWeight(), 0.0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Defensive => 'Defensive (20% shares, 80% bonds)',
            self::Cautious => 'Cautious (40% shares, 60% bonds)',
            self::Balanced => 'Balanced (60% shares, 40% bonds)',
            self::Growth => 'Growth (80% shares, 20% bonds)',
        };
    }

    /** What choosing it means for the household, in the reader's terms rather than a planner's. */
    public function note(): string
    {
        return match ($this) {
            self::Defensive => 'the steadiest year to year, and the least growth: it protects money you are '
                .'about to spend and loses ground slowly to inflation over a long retirement',
            self::Cautious => 'the mix this tool has always assumed: a lot of ballast, modest growth',
            self::Balanced => 'more growth than the cautious mix and a wider spread of outcomes with it',
            self::Growth => 'the most growth on offer here and much the roughest ride: the bad runs in the '
                .'simulation are markedly worse, which matters most in the first years of drawing on it',
        };
    }
}
