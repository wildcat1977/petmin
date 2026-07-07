<?php

namespace App\Filament\Resources\InfoLinks\Schemas;

use App\Models\InfoLink;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class InfoLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('section')
                    ->label('分區')
                    ->options(InfoLink::SECTION_LABELS)
                    ->required(),

                TextInput::make('label')
                    ->label('名稱')
                    ->required(),

                TextInput::make('url')
                    ->label('連結網址')
                    ->url()
                    ->required()
                    ->maxLength(500),

                TextInput::make('status_text')
                    ->label('狀態文字')
                    ->helperText('短狀態文字，會顯示在連結旁')
                    ->maxLength(255),

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
