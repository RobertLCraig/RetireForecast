<?php

declare(strict_types=1);

namespace App\Import\Profiles;

use App\Import\ImportException;
use App\Import\ImportProfile;
use App\Import\ImportResult;

/**
 * A profile that is recognised and listed but not yet wired to a real layout, because
 * its exact cell positions need a genuine sample export to map. It refuses to parse
 * (with a clear reason) rather than guess at a format it has not seen — so a "coming
 * soon" option can appear in the chooser without silently mis-reading a file.
 */
abstract class UncalibratedProfile implements ImportProfile
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function parse(string $contents): ImportResult
    {
        throw new ImportException(sprintf(
            'The %s reader is not calibrated yet — it needs a sample export to map its layout. For now, enter the figures by hand or use the RetireForecast template.',
            $this->label(),
        ));
    }
}
