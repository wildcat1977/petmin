<?php

namespace App\Filament\Resources\PromoCards\Pages;

use App\Filament\Resources\PromoCards\PromoCardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromoCard extends EditRecord
{
    protected static string $resource = PromoCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
