<?php

namespace App\Filament\Resources\TrashBins\Pages;

use App\Filament\Resources\TrashBins\TrashBinResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrashBins extends ListRecords
{
    protected static string $resource = TrashBinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
