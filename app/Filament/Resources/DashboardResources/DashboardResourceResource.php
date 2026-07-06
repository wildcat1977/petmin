<?php

namespace App\Filament\Resources\DashboardResources;

use App\Filament\Resources\DashboardResources\Pages\CreateDashboardResource;
use App\Filament\Resources\DashboardResources\Pages\EditDashboardResource;
use App\Filament\Resources\DashboardResources\Pages\ListDashboardResources;
use App\Filament\Resources\DashboardResources\Schemas\DashboardResourceForm;
use App\Filament\Resources\DashboardResources\Tables\DashboardResourcesTable;
use App\Models\DashboardResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DashboardResourceResource extends Resource
{
    protected static ?string $model = DashboardResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = '物資看板';

    protected static ?string $modelLabel = '物資項目';

    protected static ?string $pluralModelLabel = '物資項目';

    public static function form(Schema $schema): Schema
    {
        return DashboardResourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DashboardResourcesTable::configure($table);
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
            'index' => ListDashboardResources::route('/'),
            'create' => CreateDashboardResource::route('/create'),
            'edit' => EditDashboardResource::route('/{record}/edit'),
        ];
    }
}
