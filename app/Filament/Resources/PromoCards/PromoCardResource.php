<?php

namespace App\Filament\Resources\PromoCards;

use App\Filament\Resources\PromoCards\Pages\CreatePromoCard;
use App\Filament\Resources\PromoCards\Pages\EditPromoCard;
use App\Filament\Resources\PromoCards\Pages\ListPromoCards;
use App\Filament\Resources\PromoCards\Schemas\PromoCardForm;
use App\Filament\Resources\PromoCards\Tables\PromoCardsTable;
use App\Models\PromoCard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PromoCardResource extends Resource
{
    protected static ?string $model = PromoCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = '圖卡專區';

    protected static ?string $modelLabel = '圖卡';

    protected static ?string $pluralModelLabel = '圖卡';

    public static function form(Schema $schema): Schema
    {
        return PromoCardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromoCardsTable::configure($table);
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
            'index' => ListPromoCards::route('/'),
            'create' => CreatePromoCard::route('/create'),
            'edit' => EditPromoCard::route('/{record}/edit'),
        ];
    }
}
