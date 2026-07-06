<?php

namespace App\Filament\Resources\Shelters\Pages;

use App\Filament\Resources\Shelters\ShelterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShelters extends ListRecords
{
    protected static string $resource = ShelterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
