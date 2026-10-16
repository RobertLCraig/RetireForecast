<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\TaxYear;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Card 0122: each source key carries its own verified-on date, so the registry and a
 * parameter docblock cannot state two different dates for the same figures.
 */
final class TaxYearSourcesTest extends TestCase
{
    public function test_every_source_carries_its_own_verified_on_date(): void
    {
        // The benefits docblock must not state a verified date the registry does not record.
        $docblock = (string) file_get_contents(dirname(__DIR__, 2).'/src/TaxYear/BenefitsParameters.php');
        preg_match_all('/\d{4}-\d{2}-\d{2}/', $docblock, $stated);

        foreach (TaxYearRegistry::SUPPORTED_TAX_YEARS as $taxYear) {
            $config = TaxYearRegistry::for($taxYear);
            $byKey = $config->verifiedOnBySource;
            $recorded = array_values(array_intersect_key($byKey, array_flip(['benefits', 'attendance_allowance', 'carers_allowance'])));

            foreach ($stated[0] as $date) {
                $this->assertContains($date, $recorded, "{$taxYear}: BenefitsParameters states {$date}, which the registry does not record for its source");
            }

            $this->assertSame(array_keys($config->sources), array_keys($byKey), "{$taxYear}: every source key needs exactly one verified-on date");
            foreach ($byKey as $key => $date) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date, "{$taxYear} {$key}");
            }

            // The tax-year stamp is derived (the oldest source), never a second stored date.
            $this->assertSame(min($byKey), $config->verifiedOn, $taxYear);
        }
    }
}
