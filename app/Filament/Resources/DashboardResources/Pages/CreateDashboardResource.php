<?php

namespace App\Filament\Resources\DashboardResources\Pages;

use App\Filament\Resources\DashboardResources\DashboardResourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDashboardResource extends CreateRecord
{
    protected static string $resource = DashboardResourceResource::class;
}
