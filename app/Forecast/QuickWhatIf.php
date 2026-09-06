<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use Illuminate\Support\Str;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

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
    /**
     * The age the Attendance Allowance preset starts the claim at. It is claimable from State
     * Pension age, so 80 is deliberately the cautious end of the range rather than the earliest
     * one: a later claim is worth less and starts nearer the survivor period this is modelling.
     * The reader edits it on the person, like any other input.
     */
    public const ATTENDANCE_ALLOWANCE_FROM_AGE = 80;

    /** Preset key => the button label and the what-if's name. */
    public const PRESETS = [
        'retire_2_years_later' => 'Retire 2 years later',
        'live_10_years_longer' => 'Live 10 years longer',
        'let_out_and_rent' => 'Let out & rent elsewhere',
        'claim_attendance_allowance' => 'Claim Attendance Allowance from '.self::ATTENDANCE_ALLOWANCE_FROM_AGE,
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
            'claim_attendance_allowance' => self::claimAttendanceAllowance($baseState, $base->base_tax_year),
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
     * "Claim Attendance Allowance": every member who does not already receive a disability benefit
     * claims one from {@see ATTENDANCE_ALLOWANCE_FROM_AGE}, and its money arrives with it as a
     * tax-free income stream from the same age. Two separate things have to move together, which is
     * exactly why this is a preset rather than four hand edits: the FLAG is what opens the Pension
     * Credit severe-disability addition (and the carer addition where a partner cares), and the
     * STREAM is the benefit's own cash. Entering one without the other models half the event.
     *
     * The LOWER rate is used, which is the cautious of the two and still qualifies for the
     * addition; a household expecting the higher rate raises the stream's amount. Returns null when
     * every member already receives a benefit, so no empty what-if is ever made.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private static function claimAttendanceAllowance(array $state, string $baseTaxYear): ?array
    {
        $people = is_array($state['people'] ?? null) ? $state['people'] : [];
        $claimants = array_values(array_filter($people, static fn (array $p): bool => ! ($p['receivesDisabilityBenefit'] ?? false)));
        if ($claimants === []) {
            return null;
        }

        $benefits = TaxYearRegistry::for($baseTaxYear)->benefits;
        $weeks = TaxYearRegistry::for($baseTaxYear)->statePension->weeksPerYear;
        $annual = (string) round($benefits->attendanceAllowanceLowerWeekly->pence * $weeks / 100);
        $fromAge = (string) self::ATTENDANCE_ALLOWANCE_FROM_AGE;

        $edited = $state;
        $streams = $state['incomeStreams'] ?? [];
        foreach ($people as $i => $person) {
            if ($person['receivesDisabilityBenefit'] ?? false) {
                continue;
            }
            $edited['people'][$i]['receivesDisabilityBenefit'] = true;
            $edited['people'][$i]['disabilityBenefitFromAge'] = $fromAge;
            $streams[] = [
                'id' => (string) Str::uuid(),
                'ownerId' => (string) ($person['id'] ?? 'p1'),
                'type' => 'disability_benefit',
                'grossAnnual' => $annual,
                'frequency' => 'annual',
                'taxable' => false,
                'inflationLinked' => true,
                'startAge' => $fromAge,
                'endAge' => '',
            ];
        }
        $edited['incomeStreams'] = $streams;

        return $edited;
    }

    /**
     * "Let out & rent elsewhere": keep the home but stop living in it — let it to a tenant (so a
     * buy-to-let mortgage is no longer in breach and continues, hence the maturity action becomes
     * refinance) and rent a cheaper place. Keeps the flat (variant stay_put), adds a taxable
     * rental income (a default 5% gross yield on the home's value, editable) and a "Rent (our
     * home)" essential cost (the rent-leg figure if set, else ~4% of value). Returns null with no
     * property to let.
     *
     * The let home is flagged {@see Property::$isLet}, which is
     * the discriminator for everything about letting: its equity counts as assessable capital in
     * the pension-age means test (letting it out erodes Pension Credit and can cross the £16k
     * cliff, as in life), its mortgage interest earns the Section 24 credit, and the agent's fee,
     * the empty weeks and the repairs come off the gross rent before it is banked or taxed. What
     * is still NOT modelled is on the RESULT, not in this comment: see the `letting_caveats` note
     * in {@see ResultPresenter::inputNotes()}.
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
