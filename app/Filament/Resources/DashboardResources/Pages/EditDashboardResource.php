<?php

namespace App\Filament\Resources\DashboardResources\Pages;

use App\Filament\Resources\DashboardResources\DashboardResourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDashboardResource extends EditRecord
{
    protected static string $resource = DashboardResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
