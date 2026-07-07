<?php

namespace App\Filament\Resources\ReportCategories\Schemas;

use App\Models\ReportCategory;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ReportCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->label('代碼（slug）')
                    ->required()
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->maxLength(32)
                    ->disabled(fn (?ReportCategory $record) => $record !== null)
                    ->helperText('建立後不可修改，為程式與回報資料的鍵值'),

                TextInput::make('name')
                    ->label('名稱')
                    ->required()
                    ->maxLength(32),

                TextInput::make('emoji')
                    ->label('Emoji')
                    ->maxLength(8),

                ColorPicker::make('color')
                    ->label('顏色')
                    ->required(),

                TextInput::make('sort_order')
                    ->label('排序')
                    ->numeric()
                    ->default(0)
                    ->required(),

                Toggle::make('is_active')
                    ->label('啟用')
                    ->default(true),

                TextInput::make('extra_field.label')
                    ->label('補充欄位名稱')
                    ->maxLength(32)
                    ->nullable(),

                TextInput::make('extra_field.unit')
                    ->label('單位')
                    ->maxLength(16)
                    ->nullable(),

                TextInput::make('extra_field.min')
                    ->label('最小值')
                    ->numeric()
                    ->nullable(),

                TextInput::make('extra_field.max')
                    ->label('最大值')
                    ->numeric()
                    ->nullable(),

                Repeater::make('extra_field.presets')
                    ->label('常用預設值')
                    ->schema([
                        TextInput::make('value')
                            ->label('數值')
                            ->numeric()
                            ->nullable(),

                        TextInput::make('hint')
                            ->label('提示文字')
                            ->nullable(),
                    ])
                    ->columns(2)
                    ->default([]),
            ]);
    }
}
