<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet as PhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
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
            // Phase C1: the import populates 3-tier line items (labels preserved), not flat totals.
            ->assertSet('expenseLines.0.label', 'Rent')
            ->assertSet('expenseLines.0.amount', '12000.00')      // 1000 * 12
            ->assertSet('expenseLines.0.category', 'essential')
            ->assertSet('expenseLines.1.label', 'Fun')
            ->assertSet('expenseLines.1.amount', '2400.00')       // 200 * 12
            ->assertSet('expenseLines.1.category', 'discretionary')
            ->assertSet('people.0.grossSalary', '30000.00')      // 2408 * 12
            ->assertSet('people.0.employmentStatus', 'employed')
            ->assertSet('step', 4);                               // lands on the spending step
    }

    public function test_an_uncalibrated_profile_reports_instead_of_importing(): void
    {
        $csv = "section,label,monthly_amount\nessential,Rent,1000\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'nischa-ist') // still pending a sample export
            ->set('importFile', UploadedFile::fake()->createWithContent('ist.csv', $csv))
            ->call('import')
            ->assertHasErrors('importFile');
    }

    public function test_importing_a_conscious_spending_plan_fills_spending(): void
    {
        $csv = "Fixed Costs,,\nRent,\$1500,Monthly\nGuilt-Free Spending,,\nDining,\$400,Monthly\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'iwt-csp')
            ->set('importFile', UploadedFile::fake()->createWithContent('csp.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            // One 3-tier line per bucket, carrying the bucket's authoritative annual figure.
            ->assertSet('expenseLines.0.label', 'Fixed costs')
            ->assertSet('expenseLines.0.amount', '18000.00')   // 1500 * 12
            ->assertSet('expenseLines.0.category', 'essential')
            ->assertSet('expenseLines.1.label', 'Guilt-free spending')
            ->assertSet('expenseLines.1.amount', '4800.00')    // 400 * 12
            ->assertSet('expenseLines.1.category', 'discretionary')
            ->assertSet('step', 4);
    }

    public function test_a_multi_tab_workbook_lets_you_choose_the_tab(): void
    {
        $book = new PhpSpreadsheet;
        $book->getActiveSheet()->setTitle('Junk')->fromArray([['nothing here']]);
        $book->createSheet()->setTitle('Demo Buy')->fromArray([
            ['Person Pension DLA', 11772, 981], // income block above the expenditure header
            ['Expenditure Item', 'Deduction Amount', '% of Total Pay', '% of Take Home Pay', 'Notes'],
            ['Mortgage', 1000],
            ['Total', 1000],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $file = UploadedFile::fake()->createWithContent('workbook.xlsx', (string) file_get_contents($path));
        @unlink($path);

        $component = Livewire::test(ScenarioBuilder::class)
            ->set('importFile', $file)                       // updatedImportFile lists the tabs
            ->assertSet('importSheets', ['Junk', 'Demo Buy'])
            ->set('importProfile', 'pay-and-expenditures')
            ->set('importSheet', 'Demo Buy')
            ->call('import')
            ->assertHasNoErrors()
            // Each outgoing becomes its own essential line: Mortgage £1,000/mo -> £12,000/yr.
            ->assertSet('expenseLines.0.label', 'Mortgage')
            ->assertSet('expenseLines.0.amount', '12000.00') // 1000/mo * 12, from the chosen tab
            ->assertSet('expenseLines.0.category', 'essential');

        $this->assertCount(1, $component->get('incomeStreams')); // DLA pulled onto the form
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

    public function test_the_reconciliation_panel_confirms_a_total_that_matches_its_line_items(): void
    {
        // A bucket whose own TOTAL agrees with the sum of its line items: £3,000/mo either way.
        $csv = "Fixed Costs,,\nRent,\$2000,Monthly\nGroceries,\$1000,Monthly\nFIXED COSTS TOTAL,\$3000,Monthly\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'iwt-csp')
            ->set('importFile', UploadedFile::fake()->createWithContent('csp.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('Reconciliation')
            ->assertSee('Reconciles with')
            ->assertSee('36000.00'); // 3000 * 12
    }

    public function test_the_reconciliation_panel_flags_a_total_that_disagrees_with_its_line_items(): void
    {
        // The visible-failure proof: the stated TOTAL (£9,999/mo) does not equal the line items
        // (£3,000/mo). The importer trusts the TOTAL, so the wrong figure is pre-filled — and the
        // panel must say so loudly rather than silently picking one (CLAUDE.md integrity rule).
        $csv = "Fixed Costs,,\nRent,\$2000,Monthly\nGroceries,\$1000,Monthly\nFIXED COSTS TOTAL,\$9999,Monthly\n";

        Livewire::test(ScenarioBuilder::class)
            ->set('importProfile', 'iwt-csp')
            ->set('importFile', UploadedFile::fake()->createWithContent('csp.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('does not reconcile')
            ->assertSee('119988.00')  // 9999 * 12, the (wrong) stated total now in the form
            ->assertSee('36000.00');  // 3000 * 12, the sheet's own line items
    }
}
