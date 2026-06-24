<?php

namespace App\Filament\Resources\AssumptionSets\Pages;

use App\Filament\Resources\AssumptionSets\AssumptionSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssumptionSets extends ListRecords
{
    protected static string $resource = AssumptionSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
