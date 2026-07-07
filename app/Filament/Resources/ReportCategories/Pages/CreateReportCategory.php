<?php

namespace App\Filament\Resources\ReportCategories\Pages;

use App\Filament\Resources\ReportCategories\ReportCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportCategory extends CreateRecord
{
    protected static string $resource = ReportCategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ReportCategoryResource::normalizeExtraFieldData($data);
    }
}
