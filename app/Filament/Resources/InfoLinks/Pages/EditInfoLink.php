<?php

namespace App\Filament\Resources\InfoLinks\Pages;

use App\Filament\Resources\InfoLinks\InfoLinkResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInfoLink extends EditRecord
{
    protected static string $resource = InfoLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
