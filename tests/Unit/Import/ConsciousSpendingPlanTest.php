<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Import\ImportException;
use App\Import\Profiles\ConsciousSpendingPlan;
use Tests\TestCase;

class ConsciousSpendingPlanTest extends TestCase
{
    public function test_it_reads_a_sectioned_export_with_frequencies(): void
    {
        // Section headers, items with a Frequency column, currency amounts, and a
        // net-income line above the first bucket (which must be ignored).
        $csv = <<<'CSV'
        Conscious Spending Plan,,
        Net monthly income,"$5,000",
        ,,
        Fixed Costs,,
        Rent,"$1,500",Monthly
        Utilities,$200,Monthly
        Car payment,$200,Bi-weekly
        ,,
        Investments,,
        401k,$500,Monthly
        ,,
        Savings,,
        Emergency fund,$300,Monthly
        ,,
        Guilt-Free Spending,,
        Dining out,$400,Monthly
        Hobbies,$100,Monthly
        CSV;

        $result = (new ConsciousSpendingPlan)->parse($csv);

        // Fixed: 1500*12 + 200*12 + 200*26 (bi-weekly) = 18000 + 2400 + 5200 = 25600
        $this->assertSame('25600.00', $result->expense['essential']);
        // Guilt-Free: (400 + 100) * 12 = 6000
        $this->assertSame('6000.00', $result->expense['discretionary']);
        // CSP is net, so no gross salary is set.
        $this->assertNull($result->salaryAnnual);
        // Investments + Savings are surfaced as contributions, not spending.
        $this->assertNotEmpty($result->notes);
    }

    public function test_it_reads_a_flat_export_with_a_bucket_column_and_no_currency_symbols(): void
    {
        $csv = <<<'CSV'
        Bucket,Category,Amount,Frequency
        Fixed Costs,Rent,1500,Monthly
        Guilt-Free Spending,Dining,400,Monthly
        CSV;

        $result = (new ConsciousSpendingPlan)->parse($csv);

        $this->assertSame('18000.00', $result->expense['essential']);     // 1500 * 12
        $this->assertSame('4800.00', $result->expense['discretionary']);  // 400 * 12
    }

    public function test_it_refuses_a_file_with_no_buckets(): void
    {
        $csv = "Category,Amount\nGroceries,100\nFuel,50\n";

        $this->expectException(ImportException::class);
        (new ConsciousSpendingPlan)->parse($csv);
    }
}
