<?php

namespace App\Filament\Resources\ReportCategories;

use App\Filament\Resources\ReportCategories\Pages\CreateReportCategory;
use App\Filament\Resources\ReportCategories\Pages\EditReportCategory;
use App\Filament\Resources\ReportCategories\Pages\ListReportCategories;
use App\Filament\Resources\ReportCategories\Schemas\ReportCategoryForm;
use App\Filament\Resources\ReportCategories\Tables\ReportCategoriesTable;
use App\Models\ReportCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReportCategoryResource extends Resource
{
    protected static ?string $model = ReportCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = '回報分類';

    protected static ?string $modelLabel = '回報分類';

    protected static ?string $pluralModelLabel = '回報分類';

    public static function form(Schema $schema): Schema
    {
        return ReportCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportCategories::route('/'),
            'create' => CreateReportCategory::route('/create'),
            'edit' => EditReportCategory::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeExtraFieldData(array $data): array
    {
        if (blank($data['extra_field']['label'] ?? null)) {
            $data['extra_field'] = null;
        }

        return $data;
    }
}
