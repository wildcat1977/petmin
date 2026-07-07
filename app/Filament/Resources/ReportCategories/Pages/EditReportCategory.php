<?php

namespace App\Filament\Resources\ReportCategories\Pages;

use App\Filament\Resources\ReportCategories\ReportCategoryResource;
use App\Models\Report;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReportCategory extends EditRecord
{
    protected static string $resource = ReportCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => Report::where('category', $this->getRecord()->slug)->exists()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReportCategoryResource::normalizeExtraFieldData($data);
    }
}
