<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use Illuminate\Support\Str;

/**
 * One-click what-ifs for the common questions a reader asks of a forecast ("what if I
 * retire later?", "what if I live longer?"). Each preset edits the base's people and
 * returns the resulting sparse override delta, so a quick what-if is an ordinary
 * delta-child — the same shape a hand-built one is, just generated.
 *
 * The delta is computed through {@see BuilderStateDelta::diff()} against the base's
 * effective form-state, so it is minimal (only changed leaves) and structurally identical
 * to the base (it only retunes existing people, never adds or removes a row). A preset that
 * would change nothing for this household returns null, so an empty what-if is never made.
 */
final class QuickWhatIf
{
    /** Preset key => the button label and the what-if's name. */
    public const PRESETS = [
        'retire_2_years_later' => 'Retire 2 years later',
        'live_10_years_longer' => 'Live 10 years longer',
        'let_out_and_rent' => 'Let out & rent elsewhere',
    ];

    /**
     * The name + override delta for applying $preset to $base (a base plan), or null when
     * it would change nothing for this household.
     *
     * @return array{name: string, overrides: array<string, mixed>}|null
     */
    public static function build(Scenario $base, string $preset): ?array
    {
        if (! array_key_exists($preset, self::PRESETS)) {
            return null;
        }

        $baseState = $base->effectiveBuilderState();
        $people = is_array($baseState['people'] ?? null) ? $baseState['people'] : [];

        $edited = match ($preset) {
            'retire_2_years_later' => ['people' => self::retireLater($people, 2)] + $baseState,
            'live_10_years_longer' => ['people' => self::liveLonger($people, 10)] + $baseState,
            'let_out_and_rent' => self::letOutAndRent($baseState),
        };

        if ($edited === null) {
            return null; // nothing to model (e.g. the let-out what-if on a household with no property)
        }

        $overrides = BuilderStateDelta::diff($baseState, $edited);
        if ($overrides === []) {
            return null;
        }

        return ['name' => self::PRESETS[$preset], 'overrides' => $overrides];
    }

    /**
     * Push each still-working person's planned retirement age out by $years, clamped to the
     * builder's accepted 50–80. People without a retirement age (or already retired) are left
     * alone, so the what-if only moves what it can actually move.
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private static function retireLater(array $people, int $years): array
    {
        return array_map(function (array $person) use ($years): array {
            $working = in_array((string) ($person['employmentStatus'] ?? ''), ['employed', 'self_employed'], true);
            $age = $person['plannedRetirementAge'] ?? '';
            if ($working && is_numeric($age)) {
                $person['plannedRetirementAge'] = (string) max(50, min(80, (int) $age + $years));
            }

            return $person;
        }, $people);
    }

    /**
     * Extend each person's modelled lifespan by $years through the offset-years longevity
     * lever, relative to whatever the base already models: lengthen an existing offset or
     * fixed age, or move "peer average" onto a +N-year offset (clamped to the lever's range).
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private static function liveLonger(array $people, int $years): array
    {
        return array_map(function (array $person) use ($years): array {
            $mode = (string) ($person['longevityMode'] ?? 'peer');
            $value = $person['longevityValue'] ?? '';
            $current = is_numeric($value) ? (int) $value : 0;

            [$person['longevityMode'], $newValue] = match ($mode) {
                'offset_years' => ['offset_years', min(110, $current + $years)],
                'fixed_age' => ['fixed_age', min(110, $current + $years)],
                default => ['offset_years', $years],
            };
            $person['longevityValue'] = (string) $newValue;

            return $person;
        }, $people);
    }

    /**
     * "Let out & rent elsewhere": keep the home but stop living in it — let it to a tenant (so a
     * buy-to-let mortgage is no longer in breach and continues, hence the maturity action becomes
     * refinance) and rent a cheaper place. Keeps the flat (variant stay_put), adds a taxable
     * rental income (a default 5% gross yield on the home's value, editable) and a "Rent (our
     * home)" essential cost (the rent-leg figure if set, else ~4% of value). Returns null with no
     * property to let.
     *
     * The let home is flagged {@see \RetireForecast\FinanceEngine\Dto\Property::$isLet}, so its
     * equity counts as assessable capital in the pension-age means test — letting it out erodes
     * Pension Credit and can cross the £16k cliff, as in life. v1 caveats still flagged: BTL
     * mortgage-interest tax relief and letting voids/costs are not modelled, and the retained
     * mortgage is not netted off displayed wealth.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private static function letOutAndRent(array $state): ?array
    {
        $property = is_array($state['property'] ?? null) ? $state['property'] : [];
        $value = $property['currentValue'] ?? null;
        if (! ($state['hasProperty'] ?? false) || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        $housing = is_array($state['housing'] ?? null) ? $state['housing'] : [];
        $rent = (is_numeric($housing['annualRent'] ?? null) && (float) $housing['annualRent'] > 0)
            ? (string) $housing['annualRent']
            : (string) round((float) $value * 0.04);
        $letIncome = (string) round((float) $value * 0.05); // 5% gross yield, editable

        $owner = (string) ($state['people'][0]['id'] ?? 'p1');

        $edited = $state;
        $edited['variant'] = 'stay_put';                             // keep the flat (do not sell)
        $edited['property']['mortgageMaturityAction'] = 'refinance';  // let → the BTL is legit and continues
        $edited['property']['isLet'] = true;                         // let out → its equity is assessable capital
        $edited['incomeStreams'] = array_merge($state['incomeStreams'] ?? [], [[
            'id' => (string) Str::uuid(),
            'ownerId' => $owner,
            'type' => 'rental',
            'grossAnnual' => $letIncome,
            'frequency' => 'annual',
            'taxable' => true,
            'inflationLinked' => true,
            'startAge' => '0',
            'endAge' => '',
        ]]);
        $edited['expenseLines'] = array_merge($state['expenseLines'] ?? [], [[
            'id' => (string) Str::uuid(),
            'label' => 'Rent (our home)',
            'amount' => $rent,
            'category' => 'essential',
            'savedAsAsset' => false,
        ]]);

        return $edited;
    }
}
