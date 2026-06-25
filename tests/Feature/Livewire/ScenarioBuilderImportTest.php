<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ScenarioBuilderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_importing_the_template_pre_fills_spending_and_salary(): void
    {
        $csv = "section,label,monthly_amount\nessential,Rent,1000\ndiscretionary,Fun,200\nsalary,Pay,2408\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'retireforecast')
            ->set('importFile', UploadedFile::fake()->createWithContent('budget.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('expense.essential', '12000.00')      // 1000 * 12
            ->assertSet('expense.discretionary', '2400.00')   // 200 * 12
            ->assertSet('people.0.grossSalary', '30000.00')   // 2408 * 12
            ->assertSet('people.0.employmentStatus', 'employed')
            ->assertSet('step', 4);                            // lands on the spending step
    }

    public function test_an_uncalibrated_profile_reports_instead_of_importing(): void
    {
        $csv = "section,label,monthly_amount\nessential,Rent,1000\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'iwt-csp')
            ->set('importFile', UploadedFile::fake()->createWithContent('csp.csv', $csv))
            ->call('import')
            ->assertHasErrors('importFile');
    }

    public function test_a_file_that_does_not_match_the_template_reports_a_reason(): void
    {
        $csv = "section,label,monthly_amount\nunknown,Thing,50\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'retireforecast')
            ->set('importFile', UploadedFile::fake()->createWithContent('weird.csv', $csv))
            ->call('import')
            ->assertHasErrors('importFile');
    }
}
