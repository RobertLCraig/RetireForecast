<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Import\ImportException;
use App\Import\ImportRegistry;
use App\Import\Profiles\RetireForecastTemplate;
use App\Import\Spreadsheet;
use Tests\TestCase;

class ImportRegistryTest extends TestCase
{
    public function test_it_lists_all_profiles_and_the_calibrated_ones_are_available(): void
    {
        $registry = new ImportRegistry;

        $this->assertCount(4, $registry->all());
        $this->assertCount(3, $registry->available()); // RetireForecast template + Pay&Expenditures + IWT CSP
        $this->assertInstanceOf(RetireForecastTemplate::class, $registry->available()[0]);
    }

    public function test_the_uncalibrated_profile_refuses_to_parse_with_a_reason(): void
    {
        $profile = (new ImportRegistry)->find('nischa-ist'); // still pending a sample export
        $this->assertNotNull($profile);
        $this->assertFalse($profile->isAvailable());

        try {
            $profile->parse(Spreadsheet::fromCsv('anything'));
            $this->fail('Expected nischa-ist to refuse parsing.');
        } catch (ImportException $e) {
            $this->assertStringContainsString('not calibrated', $e->getMessage());
        }
    }

    public function test_an_unknown_key_resolves_to_null(): void
    {
        $this->assertNull((new ImportRegistry)->find('does-not-exist'));
    }
}
