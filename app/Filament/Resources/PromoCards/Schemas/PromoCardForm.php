<?php

namespace App\Filament\Resources\PromoCards\Schemas;

use App\Models\PromoCard;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PromoCardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('標題')
                    ->required(),

                Select::make('group')
                    ->label('分區')
                    ->options(PromoCard::GROUP_LABELS)
                    ->required(),

                FileUpload::make('image_path')
                    ->label('圖卡')
                    ->disk('public')
                    ->directory('cards')
                    ->image()
                    ->required(),

                TextInput::make('sort_order')
                    ->label('排序')
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label('啟用')
                    ->default(true),
            ]);
    }
}
