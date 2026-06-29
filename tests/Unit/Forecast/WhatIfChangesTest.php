<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\WhatIfChanges;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScenarioFixture;

/**
 * A what-if's overrides, made legible: each changed input becomes a labelled base -> new
 * line, so the what-if reads as a *variation* of its base. The properties that matter:
 * the base value an override replaced is read back and shown beside the new one; list rows
 * are named by their own label/identity (not a raw id); money/rate/enum values are formatted;
 * and meta fields (the auto-generated name, the wizard step) are not reported as changes.
 */
final class WhatIfChangesTest extends TestCase
{
    /** @return array<string, array{label: string, from: string, to: string}> keyed by label */
    private function byLabel(array $overrides): array
    {
        $changes = WhatIfChanges::compute(ScenarioFixture::richState(), $overrides);
        $byLabel = [];
        foreach ($changes as $change) {
            $byLabel[$change['label']] = $change;
        }

        return $byLabel;
    }

    public function test_each_changed_input_shows_its_base_value_and_new_value(): void
    {
        $changes = $this->byLabel([
            'people.p1.grossSalary' => '70000',
            'expenseLines.ess1.amount' => '31000',
            'housing.annualRent' => '20000',
            'pensions.dc1.currentValue' => '450000',
        ]);

        // A person's field is named by the person, money is shown as £, base -> new both present.
        $this->assertSame(['label' => 'P1 · gross salary', 'from' => '£62,000', 'to' => '£70,000'], $changes['P1 · gross salary']);
        // A spending line is named by its own label.
        $this->assertSame(['label' => 'Essentials · amount', 'from' => '£28,000', 'to' => '£31,000'], $changes['Essentials · amount']);
        // A housing field gets a friendly label.
        $this->assertSame(['label' => 'Rent if you sell & rent', 'from' => '£18,000', 'to' => '£20,000'], $changes['Rent if you sell & rent']);
        // A pension is named by its kind.
        $this->assertSame(['label' => 'DC pension · current value', 'from' => '£410,000', 'to' => '£450,000'], $changes['DC pension · current value']);
    }

    public function test_enum_rate_and_absent_base_values_are_formatted(): void
    {
        $changes = $this->byLabel([
            'variant' => 'buy_outright',
            'assumptionOverrides.inflation' => '3.5',
        ]);

        // The variant enum is humanised both sides (base was 'rent').
        $this->assertSame(['label' => 'Primary option', 'from' => 'Sell & rent', 'to' => 'Sell & buy cheaper'], $changes['Primary option']);
        // A rate gets a % suffix; a figure the base never set reads as "—".
        $this->assertSame(['label' => 'Inflation (CPI)', 'from' => '—', 'to' => '3.5%'], $changes['Inflation (CPI)']);
    }

    public function test_meta_fields_are_not_reported_as_changes(): void
    {
        $changes = WhatIfChanges::compute(ScenarioFixture::richState(), [
            'name' => 'Higher essentials',
            'step' => 5,
            'expenseLines.ess1.amount' => '31000',
        ]);

        // Only the substantive input change is reported (not the what-if's name or wizard step).
        $this->assertCount(1, $changes);
        $this->assertSame('Essentials · amount', $changes[0]['label']);
    }

    public function test_no_overrides_is_no_changes(): void
    {
        $this->assertSame([], WhatIfChanges::compute(ScenarioFixture::richState(), []));
    }
}
