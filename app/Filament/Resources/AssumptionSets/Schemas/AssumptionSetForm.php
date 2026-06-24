<?php

namespace App\Filament\Resources\AssumptionSets\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * The admin form curates an assumption set's metadata and chooses the default. The
 * sourced economic figures themselves (returns, volatilities, correlations, growth
 * rates) are seeded from the engine's signed-off library and are intentionally not
 * editable here: changing one means re-sourcing it, which is a deliberate act, not
 * a casual form edit. Numeric editing is a planned follow-up.
 */
class AssumptionSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('source_note')
                    ->required()
                    ->rows(4)
                    ->helperText('Where these figures come from. Every set must cite its source.'),
                Toggle::make('is_default')
                    ->helperText('The set the forecast uses by default. Only one set can be the default.'),
            ]);
    }
}
