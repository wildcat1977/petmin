<?php

namespace App\Filament\Resources\Shelters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class SheltersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->searchable(),

                TextColumn::make('address')
                    ->label('地址')
                    ->default('—'),

                TextColumn::make('capacity')
                    ->label('容量')
                    ->default('—'),

                ToggleColumn::make('is_open')
                    ->label('開設中')
                    ->afterStateUpdated(fn () => Cache::forget('map.shelters')),

                BadgeColumn::make('source')
                    ->label('資料來源')
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label('更新時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_open')
                    ->label('開設狀態')
                    ->trueLabel('開設中')
                    ->falseLabel('未開設'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('is_open', 'desc');
    }
}
