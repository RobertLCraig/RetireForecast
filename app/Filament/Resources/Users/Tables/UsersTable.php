<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                // Admin-panel access. Toggling persists `is_admin`, which
                // User::canAccessPanel() reads. This is the tighter gate the
                // interpretation grant sits behind, so keep it to trusted users only.
                ToggleColumn::make('is_admin')
                    ->label('Admin access'),
                // The grant itself: toggling persists `can_interpret`, which the
                // `interpret` Gate reads. Grant only to yourself or family on a live
                // deployment; never to arbitrary public users.
                ToggleColumn::make('can_interpret')
                    ->label('Interpretation mode'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
