<?php

namespace App\Filament\Resources\PromoCards\Pages;

use App\Filament\Resources\PromoCards\PromoCardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoCards extends ListRecords
{
    protected static string $resource = PromoCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
