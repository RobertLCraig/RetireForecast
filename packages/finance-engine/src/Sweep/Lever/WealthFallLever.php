<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Pension;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * **Losing it.** An immediate, across-the-board fall in everything the household owns, on the
 * base date, swept as a FRACTION of total wealth (0.25 = a quarter of it gone). This is the
 * lever behind "capacity for loss": how far can wealth fall before the essential spending floor
 * stops being met?
 *
 * **One definition of wealth, shared with the screen.** {@see baseWealth} sums the same three
 * legs {@see YearResult::$totalWealth} reports — liquid
 * accounts, money-purchase pots, and home equity NET of the mortgage and floored at zero — read
 * at the base date rather than at a year end. A fall of `f` takes `f` off each of those legs, so
 * total wealth falls by exactly `f`: the percentage a caller sweeps and the cash figure it prints
 * are the same quantity, and neither can drift from the wealth line the rest of the tool shows.
 *
 * What it does NOT claim:
 *  - **It is not a market model.** Cash does not fall in a crash and houses and equities do not
 *    fall together or by the same amount. This is a capacity measure — "if everything you own
 *    were worth `f` less" — and marking every leg down uniformly is the cautious reading, so the
 *    capacity it reports is never flattered by assuming part of the wealth is safe.
 *  - **The debt does not fall with the asset.** A fall is applied to the home's EQUITY, so the
 *    mortgage stays exactly where it is and the value comes down by the whole loss — which is
 *    what actually happens, and why a geared household has less capacity than a mortgage-free
 *    one. A home already worth less than the loan secured on it has no equity left to lose (the
 *    No-Negative-Equity floor already holds it at zero), so it is left alone.
 *  - **Defined-benefit and State pensions are untouched.** They are income, not wealth: there is
 *    no pot to mark down, and they are what a household's remaining capacity ultimately rests on.
 *
 * CRN-safe: the fall changes no one's lifespan and adds or removes nobody, so the random streams
 * a Monte Carlo consumes are unchanged between grid points. Success is monotone decreasing in
 * the fall — losing more can only make the floor harder to meet.
 */
final class WealthFallLever implements SweepLever
{
    /**
     * $value is the fraction of total wealth lost, clamped to [0, 1]: 0 leaves the household
     * exactly as it is, 1 leaves it with nothing but its income.
     */
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $keep = 1.0 - min(1.0, max(0.0, $value));

        $fallen = $household
            ->withAccounts(self::fallenAccounts($household->accounts, $keep))
            ->withPensions(self::fallenPensions($household->pensions, $keep));

        $home = self::fallenHome($household->primaryResidence, $keep, $settings->baseYear);

        return new SweepInputs($home === null ? $fallen : $fallen->withPrimaryResidence($home), $settings);
    }

    /**
     * Total wealth at the base date on the project's one definition: liquid accounts + invested
     * pension pots + home equity net of the mortgage, floored at zero. This is the denominator a
     * fall is a percentage OF, so it is computed here beside the fall itself rather than anywhere
     * a second definition could drift from it.
     */
    public static function baseWealth(Household $household, ForecastSettings $settings): Money
    {
        $wealth = Money::zero();

        foreach ($household->accounts as $account) {
            $wealth = $wealth->plus($account->balance);
        }

        foreach ($household->pensions as $pension) {
            if ($pension instanceof DcPension) {
                $wealth = $wealth->plus($pension->currentValue);
            }
        }

        return $wealth->plus(self::homeEquity($household->primaryResidence, $settings->baseYear));
    }

    public function name(): string
    {
        return 'fall in total wealth';
    }

    public function unit(): string
    {
        return 'fraction of total wealth';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Decreasing;
    }

    /**
     * Every account marked down to $keep of its balance. The cost basis does not move in a fall,
     * so the unrealised gain comes down by the cash lost — floored at zero because this engine
     * carries no capital loss forward, and crediting relief it never gives would flatter the
     * stressed plan.
     *
     * @param  list<Account>  $accounts
     * @return list<Account>
     */
    private static function fallenAccounts(array $accounts, float $keep): array
    {
        return array_map(static function (Account $account) use ($keep): Account {
            $balance = Money::fromPence((int) round($account->balance->pence * $keep));
            $lost = $account->balance->minus($balance);

            return $account->withValue(
                $balance,
                $account->unrealisedGain?->minus($lost)->minZero(),
            );
        }, $accounts);
    }

    /**
     * Every money-purchase pot marked down to $keep of its value. Defined-benefit entitlements
     * and State Pension pass through untouched — they are income, not a pot that can fall.
     *
     * @param  list<Pension>  $pensions
     * @return list<Pension>
     */
    private static function fallenPensions(array $pensions, float $keep): array
    {
        return array_map(static fn (Pension $pension): Pension => $pension instanceof DcPension
            ? $pension->withCurrentValue(Money::fromPence((int) round($pension->currentValue->pence * $keep)))
            : $pension, $pensions);
    }

    /**
     * The home re-valued so its EQUITY falls to $keep of itself while the mortgage stays put.
     * The household's beneficial share cancels out of that arithmetic — cutting the whole
     * property's equity by a fraction cuts the household's share of it by the same fraction — so
     * the share is applied only where wealth is measured ({@see homeEquity}), never twice.
     *
     * Returns null when there is no home, and the home UNCHANGED when the loan already exceeds
     * its value: there is no equity left for a fall to take.
     */
    private static function fallenHome(?Property $home, float $keep, int $baseYear): ?Property
    {
        if ($home === null) {
            return null;
        }

        $debt = self::openingMortgage($home, $baseYear);
        $equity = $home->currentValue->minus($debt);
        if (! $equity->isPositive()) {
            return $home;
        }

        return $home->withCurrentValue($debt->plus(Money::fromPence((int) round($equity->pence * $keep))));
    }

    /** The household's share of the home's value less the loan on it, floored at zero (NNEG). */
    private static function homeEquity(?Property $home, int $baseYear): Money
    {
        if ($home === null) {
            return Money::zero();
        }

        $share = $home->ownershipShare?->asFraction() ?? 1.0;
        // Scale each leg then subtract, exactly as PathProjector seeds them, so a part-owned
        // home's wealth here agrees with the wealth line the projection reports.
        $value = Money::fromPence((int) round($home->currentValue->pence * $share));
        $debt = Money::fromPence((int) round(self::openingMortgage($home, $baseYear)->pence * $share));

        return $value->minus($debt)->minZero();
    }

    /**
     * What is owed on the home in the base year, whole-property. An amortising loan reads its
     * balance off its own schedule — the same figure the projector opens with, so a mortgage
     * part-way through its term is not treated as if it were untouched.
     */
    private static function openingMortgage(Property $home, int $baseYear): Money
    {
        $owed = $home->outstandingMortgage ?? Money::zero();

        return $home->repaymentTerms === null
            ? $owed
            : AmortisationSchedule::for($owed, $home->repaymentTerms)->openingBalanceIn($baseYear);
    }
}
