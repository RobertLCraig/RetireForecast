<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Import\ImportException;
use App\Import\ImportRegistry;
use App\Import\Profiles\RetireForecastTemplate;
use Tests\TestCase;

class ImportRegistryTest extends TestCase
{
    public function test_it_lists_all_profiles_but_only_the_calibrated_one_is_available(): void
    {
        $registry = new ImportRegistry;

        $this->assertCount(3, $registry->all());
        $this->assertCount(1, $registry->available());
        $this->assertInstanceOf(RetireForecastTemplate::class, $registry->available()[0]);
    }

    public function test_uncalibrated_profiles_refuse_to_parse_with_a_reason(): void
    {
        $registry = new ImportRegistry;

        foreach (['iwt-csp', 'nischa-ist'] as $key) {
            $profile = $registry->find($key);
            $this->assertNotNull($profile);
            $this->assertFalse($profile->isAvailable());

            try {
                $profile->parse('anything');
                $this->fail("Expected {$key} to refuse parsing.");
            } catch (ImportException $e) {
                $this->assertStringContainsString('not calibrated', $e->getMessage());
            }
        }
    }

    public function test_an_unknown_key_resolves_to_null(): void
    {
        $this->assertNull((new ImportRegistry)->find('does-not-exist'));
    }
}
