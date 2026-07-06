<?php

namespace App\Filament\Resources\Shelters\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ShelterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('名稱')
                    ->required(),

                TextInput::make('latitude')
                    ->label('緯度')
                    ->numeric()
                    ->required(),

                TextInput::make('longitude')
                    ->label('經度')
                    ->numeric()
                    ->required(),

                TextInput::make('address')
                    ->label('地址'),

                TextInput::make('capacity')
                    ->label('容量')
                    ->numeric(),

                Toggle::make('is_open')
                    ->label('災時開設中'),

                TextInput::make('source')
                    ->label('資料來源')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
