<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Whose State Pension to defer, and for how long: set ONE named person's State Pension deferral to
 * the swept number of years. Deferring delays the claim — the person forgoes the pension for the
 * deferral period, then draws an uplifted rate for life — so it is a genuine trade-off, not a free
 * uplift: it pays off only if the person lives long enough past the later start to recoup the
 * forgone years. On a survivor-cliff couple the insight is WHOSE pension to defer — deferring the
 * likely SURVIVOR's raises the income floor they lean on after the first death, while deferring the
 * first-dier's is largely wasted (they may not live to collect the uplift, and a State Pension is
 * not inherited). Picking the person is how the caller asks that question.
 *
 * Success is therefore NOT monotone in the deferral ({@see LeverDirection::Unknown}: a little helps
 * the long-lived survivor, too much loses more forgone years than the uplift returns) — the sweep
 * reports the first crossing and flags that others may exist, never a single monotone fit.
 *
 * The lever changes only the deferral figure on one person's entitlement — a deterministic uplift
 * and a shifted claim year — so it touches neither the mortality draws nor the return path: common
 * random numbers stay aligned across the grid even though the direction is Unknown. Only the named
 * person is touched; everyone else (and every non-State pension) passes through unchanged.
 */
final class StatePensionDeferralLever implements SweepLever
{
    /** DWP annualises the State Pension at 52 weeks; the grid sweeps whole years, converted here. */
    private const WEEKS_PER_YEAR = 52;

    public function __construct(private readonly string $personId) {}

    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $weeks = max(0, (int) round($value * self::WEEKS_PER_YEAR));

        $pensions = array_map(
            fn ($pension) => $pension instanceof StatePensionEntitlement && $pension->ownerId === $this->personId
                ? $pension->withDeferralWeeks($weeks)
                : $pension,
            $household->pensions,
        );

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $household->persons, $household->expenseProfile,
                $pensions, $household->accounts, $household->incomeStreams,
                $household->primaryResidence, $household->relationshipStatus,
                $household->capitalReceipts, $household->realisedGainsAtStart,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'how long one of you defers the State Pension';
    }

    public function unit(): string
    {
        return 'years';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Unknown;
    }
}
