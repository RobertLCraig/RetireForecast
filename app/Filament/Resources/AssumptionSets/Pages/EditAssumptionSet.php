<?php

namespace App\Filament\Resources\AssumptionSets\Pages;

use App\Filament\Resources\AssumptionSets\AssumptionSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssumptionSet extends EditRecord
{
    protected static string $resource = AssumptionSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
