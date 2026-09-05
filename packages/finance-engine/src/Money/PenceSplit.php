<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Money;

/**
 * Divides a sum of pence between the people it belongs to (board card 0040).
 *
 * The engine's assets are INDIVIDUALLY owned, because the English care means test and the
 * first-death estate both assess the individual and not the household. Three places used to
 * hand a whole sum to whoever happened to be declared first, so the model's answer moved with
 * the order two people were typed in. This is the one home of the rule that replaced them.
 *
 * Every division here is ORDER-INDEPENDENT, which is the property the card is about: equal
 * weights give equal shares, and the leftover pennies that no whole division can place go to
 * the lowest person id rather than to the first-declared person. Sorting ids rather than
 * taking the first is the difference between a rule and a coincidence.
 */
final class PenceSplit
{
    /**
     * Split evenly: a jointly held asset, or money nobody in particular generated.
     *
     * @param  list<string>  $ids
     * @return array<string, int> id => pence
     */
    public static function evenly(int $pence, array $ids): array
    {
        return self::byWeight($pence, array_fill_keys($ids, 1));
    }

    /**
     * Split in proportion to $weights, which are whatever makes one person's claim on the money
     * larger than another's (for the banked surplus, the net income each of them produced).
     * A negative weight is not a claim, so it is floored at zero; when no weight is positive
     * nothing is attributable and the sum splits evenly instead, which is the fallback the
     * acceptance criterion asks for rather than an edge case.
     *
     * @param  array<string, int>  $weights
     * @return array<string, int> id => pence
     */
    public static function byWeight(int $pence, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $weights = array_map(static fn (int $weight): int => max(0, $weight), $weights);
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
        }
        $total = array_sum($weights);

        if ($pence <= 0) {
            return array_fill_keys(array_keys($weights), 0);
        }

        $split = [];
        $placed = 0;
        foreach ($weights as $id => $weight) {
            $share = intdiv($pence * $weight, $total);
            $split[$id] = $share;
            $placed += $share;
        }

        $ids = array_keys($split);
        sort($ids);
        $split[$ids[0]] += $pence - $placed;

        return $split;
    }
}
