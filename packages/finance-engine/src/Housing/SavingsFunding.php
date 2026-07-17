<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Draws a one-off lump from the household's LIQUID savings at the base date — the
 * documented funding source for a purchase gap the sale proceeds don't cover.
 * Pensions are never touched (a forced pension draw would trigger income tax and
 * possibly the MPAA; if the household wants pension money in the plan they model
 * the withdrawal explicitly).
 *
 * The draw order is tier-major, mirroring the in-projection drawdown order
 * ({@see PathProjector} `fundShortfall`): cash first (Cash + Premium Bonds — the
 * same pairing the projector folds into its 'cash' bucket), then GIA, then ISA;
 * within a tier, persons in declaration order, then accounts in declaration order.
 *
 * A GIA draw is a disposal: it realises the pro-rata slice of the account's
 * unrealised gain (via the single slice-split definition,
 * {@see PathProjector::disposeGiaSlice}) and reduces the carried unrealisedGain to
 * match, so the projector's cost-basis derivation (balance − unrealisedGain) stays
 * exact and a later disposal is never taxed twice. The realised gains are reported
 * per person so year-0 CGT can be charged on them.
 */
final class SavingsFunding
{
    /** The draw tiers, in order: cash-like first, then GIA, then ISA. Pensions are not accounts. */
    private const TIERS = [
        [AccountType::Cash, AccountType::PremiumBonds],
        [AccountType::Gia],
        [AccountType::Isa],
    ];

    /**
     * Draw up to $need from the household's liquid accounts. The result's `drawn` is
     * capped at what was available; the caller decides how any remainder is funded
     * (a mortgage) or surfaced (an unfunded gap).
     */
    public static function draw(Household $household, Money $need): SavingsFundingResult
    {
        $remaining = $need->pence;
        $accounts = $household->accounts;
        $realisedGains = [];
        foreach ($household->persons as $person) {
            $realisedGains[$person->id] = Money::zero();
        }

        foreach (self::TIERS as $tier) {
            foreach ($household->persons as $person) {
                foreach ($accounts as $i => $account) {
                    if ($remaining <= 0) {
                        break 3;
                    }
                    if ($account->ownerId !== $person->id || ! in_array($account->type, $tier, true)) {
                        continue;
                    }
                    $take = min($remaining, $account->balance->pence);
                    if ($take <= 0) {
                        continue;
                    }

                    // A GIA disposal realises the pro-rata gain slice and consumes the matching
                    // basis; the account's carried unrealisedGain shrinks by the realised slice so
                    // balance − unrealisedGain (the projector's basis) stays exact.
                    $gain = $account->unrealisedGain;
                    if ($account->type === AccountType::Gia && $gain !== null && $gain->isPositive()) {
                        $basis = $account->balance->pence - $gain->pence;
                        [$gainSlice] = PathProjector::disposeGiaSlice($account->balance->pence, $basis, $take);
                        $realisedGains[$person->id] = $realisedGains[$person->id]->plus(Money::fromPence($gainSlice));
                        $gain = Money::fromPence($gain->pence - $gainSlice);
                    }

                    $accounts[$i] = new Account(
                        $account->ownerId,
                        $account->type,
                        Money::fromPence($account->balance->pence - $take),
                        $gain,
                        $account->yield,
                        $account->ongoingContributions,
                    );
                    $remaining -= $take;
                }
            }
        }

        return new SavingsFundingResult(
            accounts: array_values($accounts),
            drawn: Money::fromPence($need->pence - $remaining),
            realisedGains: $realisedGains,
        );
    }
}
