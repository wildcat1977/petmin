<?php

namespace App\Filament\Resources\TrashBins;

use App\Filament\Resources\TrashBins\Pages\CreateTrashBin;
use App\Filament\Resources\TrashBins\Pages\EditTrashBin;
use App\Filament\Resources\TrashBins\Pages\ListTrashBins;
use App\Filament\Resources\TrashBins\Schemas\TrashBinForm;
use App\Filament\Resources\TrashBins\Tables\TrashBinsTable;
use App\Models\TrashBin;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TrashBinResource extends Resource
{
    protected static ?string $model = TrashBin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?string $navigationLabel = '垃圾桶點位';

    protected static ?string $modelLabel = '垃圾桶點位';

    protected static ?string $pluralModelLabel = '垃圾桶點位';

    public static function form(Schema $schema): Schema
    {
        return TrashBinForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrashBinsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrashBins::route('/'),
            'create' => CreateTrashBin::route('/create'),
            'edit' => EditTrashBin::route('/{record}/edit'),
        ];
    }
}
