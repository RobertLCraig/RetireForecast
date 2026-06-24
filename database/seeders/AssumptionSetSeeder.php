<?php

namespace Database\Seeders;

use App\Finance\Mapping\AssumptionSetMapper;
use App\Models\AssumptionSet;
use Illuminate\Database\Seeder;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;

/**
 * Seeds the shipped, sourced assumption sets from the engine's
 * {@see AssumptionSetLibrary} into the database so they are admin-editable. The
 * library stays the source of truth for the shipped figures; this mirrors them in.
 * Idempotent: re-running refreshes each set's figures by name.
 */
class AssumptionSetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AssumptionSetLibrary::all() as $dto) {
            AssumptionSet::updateOrCreate(
                ['name' => $dto->name],
                [
                    'source_note' => $dto->sourceNote,
                    'is_default' => $dto->isDefault,
                    'payload' => AssumptionSetMapper::payload($dto),
                ],
            );
        }
    }
}
