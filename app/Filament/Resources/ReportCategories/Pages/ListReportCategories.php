<?php

namespace App\Filament\Resources\ReportCategories\Pages;

use App\Filament\Resources\ReportCategories\ReportCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportCategories extends ListRecords
{
    protected static string $resource = ReportCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
