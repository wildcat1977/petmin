<?php

namespace App\Filament\Resources\PromoCards\Tables;

use App\Models\PromoCard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PromoCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('排序')
                    ->sortable(),

                ImageColumn::make('image_path')
                    ->label('圖卡')
                    ->disk('public'),

                TextColumn::make('title')
                    ->label('標題')
                    ->searchable(),

                TextColumn::make('group')
                    ->label('分區')
                    ->formatStateUsing(fn (string $state): string => PromoCard::GROUP_LABELS[$state] ?? $state),

                ToggleColumn::make('is_active')
                    ->label('啟用'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
