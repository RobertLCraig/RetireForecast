<?php

namespace App\Filament\Resources\AssumptionSets;

use App\Filament\Resources\AssumptionSets\Pages\CreateAssumptionSet;
use App\Filament\Resources\AssumptionSets\Pages\EditAssumptionSet;
use App\Filament\Resources\AssumptionSets\Pages\ListAssumptionSets;
use App\Filament\Resources\AssumptionSets\Schemas\AssumptionSetForm;
use App\Filament\Resources\AssumptionSets\Tables\AssumptionSetsTable;
use App\Models\AssumptionSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AssumptionSetResource extends Resource
{
    protected static ?string $model = AssumptionSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return AssumptionSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssumptionSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssumptionSets::route('/'),
            'create' => CreateAssumptionSet::route('/create'),
            'edit' => EditAssumptionSet::route('/{record}/edit'),
        ];
    }
}
