<?php

namespace App\Filament\Resources\InfoLinks;

use App\Filament\Resources\InfoLinks\Pages\CreateInfoLink;
use App\Filament\Resources\InfoLinks\Pages\EditInfoLink;
use App\Filament\Resources\InfoLinks\Pages\ListInfoLinks;
use App\Filament\Resources\InfoLinks\Schemas\InfoLinkForm;
use App\Filament\Resources\InfoLinks\Tables\InfoLinksTable;
use App\Models\InfoLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InfoLinkResource extends Resource
{
    protected static ?string $model = InfoLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = '資訊彙整看板';

    protected static ?string $modelLabel = '資訊連結';

    protected static ?string $pluralModelLabel = '資訊連結';

    public static function form(Schema $schema): Schema
    {
        return InfoLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InfoLinksTable::configure($table);
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
            'index' => ListInfoLinks::route('/'),
            'create' => CreateInfoLink::route('/create'),
            'edit' => EditInfoLink::route('/{record}/edit'),
        ];
    }
}
