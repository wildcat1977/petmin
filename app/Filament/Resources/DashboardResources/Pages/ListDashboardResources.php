<?php

namespace App\Filament\Resources\DashboardResources\Pages;

use App\Filament\Resources\DashboardResources\DashboardResourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDashboardResources extends ListRecords
{
    protected static string $resource = DashboardResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
