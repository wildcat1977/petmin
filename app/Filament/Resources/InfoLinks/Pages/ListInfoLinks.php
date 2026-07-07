<?php

namespace App\Filament\Resources\InfoLinks\Pages;

use App\Filament\Resources\InfoLinks\InfoLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInfoLinks extends ListRecords
{
    protected static string $resource = InfoLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
