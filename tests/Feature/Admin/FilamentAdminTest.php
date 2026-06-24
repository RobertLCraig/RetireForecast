<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\TaxYearAudit;
use App\Filament\Resources\AssumptionSets\AssumptionSetResource;
use App\Models\AssumptionSet;
use App\Models\User;
use Database\Seeders\AssumptionSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_from_the_admin_panel(): void
    {
        $this->get(AssumptionSetResource::getUrl('index'))->assertRedirect();
    }

    public function test_an_authenticated_user_can_list_assumption_sets(): void
    {
        $this->seed(AssumptionSetSeeder::class);

        $this->actingAs(User::factory()->create())
            ->get(AssumptionSetResource::getUrl('index'))
            ->assertOk()
            ->assertSee('FCA default');
    }

    public function test_the_tax_year_audit_page_shows_sourced_figures(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(TaxYearAudit::getUrl())
            ->assertOk()
            ->assertSee('Tax year 2025-26')
            ->assertSee('£12,570.00')   // personal allowance
            ->assertSee('Verified 2026-06-24');
    }

    public function test_only_one_assumption_set_can_be_the_default(): void
    {
        $this->seed(AssumptionSetSeeder::class);

        $other = AssumptionSet::where('is_default', false)->firstOrFail();
        $other->update(['is_default' => true]);

        $this->assertSame(1, AssumptionSet::where('is_default', true)->count());
        $this->assertTrue($other->fresh()->is_default);
    }
}
