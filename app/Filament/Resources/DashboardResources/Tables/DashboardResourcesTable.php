<?php

namespace App\Filament\Resources\DashboardResources\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class DashboardResourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('排序')
                    ->sortable(),

                TextColumn::make('label')
                    ->label('物資名稱')
                    ->searchable(),

                TextInputColumn::make('current_qty')
                    ->label('現有數量')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0']),

                TextColumn::make('max_qty')
                    ->label('數量上限'),

                TextColumn::make('unit')
                    ->label('單位')
                    ->default('—'),
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
