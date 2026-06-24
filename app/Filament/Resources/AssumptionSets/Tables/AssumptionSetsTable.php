<?php

namespace App\Filament\Resources\AssumptionSets\Tables;

use App\Models\AssumptionSet;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssumptionSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('asset_classes')
                    ->label('Asset classes')
                    ->state(fn (AssumptionSet $record): int => count($record->payload['assetClasses'] ?? [])),
                TextColumn::make('inflation_mean')
                    ->label('Inflation (mean)')
                    ->state(fn (AssumptionSet $record): string => number_format(($record->payload['inflationMean'] ?? 0) / 100, 2).'%'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
