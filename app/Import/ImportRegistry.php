<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Profiles\ConsciousSpendingPlan;
use App\Import\Profiles\IntentionalSpendingTracker;
use App\Import\Profiles\PayAndExpenditures;
use App\Import\Profiles\RetireForecastTemplate;

/**
 * The set of spreadsheet readers offered in the builder. New layouts (or a calibrated
 * IWT / Nischa profile) are added here; the UI lists them all, marking the not-yet-ready
 * ones, and looks one up by key when the user imports.
 */
final class ImportRegistry
{
    /** @return list<ImportProfile> */
    public function all(): array
    {
        return [
            new RetireForecastTemplate,
            new PayAndExpenditures,
            new ConsciousSpendingPlan,
            new IntentionalSpendingTracker,
        ];
    }

    /** @return list<ImportProfile> only the profiles wired to a real layout */
    public function available(): array
    {
        return array_values(array_filter($this->all(), static fn (ImportProfile $p): bool => $p->isAvailable()));
    }

    public function find(string $key): ?ImportProfile
    {
        foreach ($this->all() as $profile) {
            if ($profile->key() === $key) {
                return $profile;
            }
        }

        return null;
    }
}
