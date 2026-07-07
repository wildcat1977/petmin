<?php

namespace App\Filament\Resources\ReportCategories\Tables;

use App\Models\Report;
use App\Models\ReportCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ReportCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('排序')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('名稱')
                    ->formatStateUsing(fn (ReportCategory $record) => trim("{$record->emoji} {$record->name}"))
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('代碼')
                    ->color('gray')
                    ->searchable(),

                ColorColumn::make('color')
                    ->label('顏色'),

                ToggleColumn::make('is_active')
                    ->label('啟用'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (ReportCategory $record) => Report::where('category', $record->slug)->exists()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $protectedSlugs = Report::whereIn('category', $records->pluck('slug'))
                                ->distinct()
                                ->pluck('category');

                            $records
                                ->reject(fn (ReportCategory $record) => $protectedSlugs->contains($record->slug))
                                ->each->delete();
                        }),
                ]),
            ]);
    }
}
