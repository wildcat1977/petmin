<?php

namespace App\Filament\Resources\Shelters\Pages;

use App\Filament\Resources\Shelters\ShelterResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;

class CreateShelter extends CreateRecord
{
    protected static string $resource = ShelterResource::class;

    /**
     * 新增收容所後清除公開地圖快取
     */
    protected function afterCreate(): void
    {
        Cache::forget('map.shelters');
    }
}
