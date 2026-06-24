<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuilderStateFixture;
use Tests\Support\HouseholdFixture;

/**
 * The builder is the third consumer of the one canonical shape. This proves the
 * assembly is lossless: form-shaped strings covering every nested DTO and optional
 * field rebuild exactly the rich {@see HouseholdFixture} household and housing
 * action, pounds parsed to exact pence with no float drift.
 */
class HouseholdAssemblerTest extends TestCase
{
    public function test_it_rebuilds_the_full_household_dto_from_form_state(): void
    {
        $assembled = (new HouseholdAssembler)->assemble(BuilderStateFixture::full());

        $this->assertEquals(HouseholdFixture::household(), $assembled['household']);
        $this->assertEquals(HouseholdFixture::housingAction(), $assembled['housingAction']);
    }

    public function test_pounds_and_pence_parse_to_exact_pence(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expense' => ['essential' => '28000.50', 'discretionary' => '', 'survivorFactor' => ''],
            'pensions' => [['ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25']],
        ]);

        $this->assertSame(2_800_050, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(23_025, $household->pensions[0]->weeklyForecast->pence);
    }
}
