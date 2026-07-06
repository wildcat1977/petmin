<?php

namespace App\Filament\Resources\DashboardResources\Schemas;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DashboardResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('物資名稱')
                    ->required(),

                TextInput::make('current_qty')
                    ->label('現有數量')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (is_numeric($value) && is_numeric($get('max_qty')) && (int) $value > (int) $get('max_qty')) {
                                $fail('現量不得超過上限');
                            }
                        },
                    ]),

                TextInput::make('max_qty')
                    ->label('數量上限')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                TextInput::make('unit')
                    ->label('單位')
                    ->maxLength(16)
                    ->placeholder('例：箱／組／份'),

                TextInput::make('sort_order')
                    ->label('排序')
                    ->numeric()
                    ->default(0),
            ]);
    }
}
