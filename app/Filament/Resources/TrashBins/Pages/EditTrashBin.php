<?php

namespace App\Filament\Resources\TrashBins\Pages;

use App\Filament\Resources\TrashBins\TrashBinResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrashBin extends EditRecord
{
    protected static string $resource = TrashBinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
