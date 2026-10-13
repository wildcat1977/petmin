<?php

namespace App\Filament\Resources\TrashBins\Schemas;

use App\Models\TrashBin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TrashBinForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('address')
                    ->label('地點／地址')
                    ->required(),

                Select::make('kind')
                    ->label('種類')
                    ->options(TrashBin::KIND_LABELS)
                    ->default(TrashBin::KIND_CLEAN_BOX)
                    ->required(),

                Select::make('status')
                    ->label('狀態')
                    ->options(TrashBin::STATUS_LABELS)
                    ->default(TrashBin::STATUS_ACTIVE)
                    ->required(),

                TextInput::make('district')
                    ->label('行政區'),

                TextInput::make('latitude')
                    ->label('緯度')
                    ->numeric()
                    ->required(),

                TextInput::make('longitude')
                    ->label('經度')
                    ->numeric()
                    ->required(),

                TextInput::make('box_count')
                    ->label('數量')
                    ->numeric()
                    ->default(1),

                TextInput::make('note')
                    ->label('備註'),

                TextInput::make('source')
                    ->label('資料來源')
                    ->default(TrashBin::SOURCE_CROWD)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create'),
            ]);
    }
}
