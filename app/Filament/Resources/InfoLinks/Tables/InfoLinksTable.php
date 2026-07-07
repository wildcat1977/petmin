<?php

namespace App\Filament\Resources\InfoLinks\Tables;

use App\Models\InfoLink;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class InfoLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('排序')
                    ->sortable(),

                TextColumn::make('section')
                    ->label('分區')
                    ->formatStateUsing(fn (string $state): string => InfoLink::SECTION_LABELS[$state] ?? $state),

                TextColumn::make('label')
                    ->label('名稱')
                    ->searchable(),

                TextColumn::make('url')
                    ->label('連結網址')
                    ->limit(40),

                TextColumn::make('status_text')
                    ->label('狀態文字')
                    ->default('—'),

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
