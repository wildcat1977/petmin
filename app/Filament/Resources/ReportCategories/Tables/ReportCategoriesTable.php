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
                    ->description(fn (ReportCategory $record) => $record->name_en)
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('代碼')
                    ->color('gray')
                    ->searchable(),

                // ColorColumn 直接以 hex 渲染色塊；badge 的 ->color() 只吃 palette 名稱、不吃 hex
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
                        // Bulk 選取無法像 row action 逐筆 hidden，改為略過受保護分類並以
                        // reportBulkProcessingFailure() 走 Filament 的部分失敗通知（避免通知宣稱全數刪除）
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            $protectedSlugs = Report::whereIn('category', $records->pluck('slug'))
                                ->distinct()
                                ->pluck('category');

                            $records->each(function (ReportCategory $record) use ($action, $protectedSlugs): void {
                                if ($protectedSlugs->contains($record->slug)) {
                                    $action->reportBulkProcessingFailure(
                                        'hasReports',
                                        fn (int $count): string => "{$count} 個分類已有回報資料，僅能停用，未刪除。",
                                    );

                                    return;
                                }

                                $record->delete();
                            });
                        }),
                ]),
            ]);
    }
}
