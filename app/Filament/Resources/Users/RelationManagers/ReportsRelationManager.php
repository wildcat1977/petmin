<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Report;
use App\Models\ReportCategory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = '通報紀錄';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('照片')
                    ->disk('public')
                    ->height(52)
                    ->width(70)
                    ->defaultImageUrl(null),

                BadgeColumn::make('category')
                    ->label('分類')
                    ->color(fn (string $state) => match ($state) {
                        Report::CATEGORY_FLOOD => 'info',
                        Report::CATEGORY_ROAD_BLOCKED => 'warning',
                        Report::CATEGORY_POWER_OUTAGE => 'warning',
                        Report::CATEGORY_STRUCTURAL_DAMAGE => 'danger',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state) => ReportCategory::labels()[$state] ?? $state),

                TextColumn::make('description')
                    ->label('描述')
                    ->limit(50)
                    ->default('—')
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('address')
                    ->label('地點')
                    ->limit(30)
                    ->default(fn ($record) => $record->latitude.', '.$record->longitude),

                BadgeColumn::make('status')
                    ->label('狀態')
                    ->color(fn (string $state) => match ($state) {
                        Report::STATUS_PENDING => 'gray',
                        Report::STATUS_APPROVED => 'success',
                        Report::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Report::STATUS_PENDING => '待審核',
                        Report::STATUS_APPROVED => '✅ 已核准（照片公開）',
                        Report::STATUS_REJECTED => '已拒絕',
                        default => $state,
                    }),

                BadgeColumn::make('verification_level')
                    ->label('核實')
                    ->color(fn (string $state) => match ($state) {
                        Report::VERIFICATION_UNVERIFIED => 'gray',
                        Report::VERIFICATION_CORROBORATED => 'info',
                        Report::VERIFICATION_OFFICIAL => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => Report::VERIFICATION_LABELS[$state] ?? $state),

                TextColumn::make('created_at')
                    ->label('通報時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('分類')
                    ->options(fn () => ReportCategory::labels()),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        Report::STATUS_PENDING => '待審核',
                        Report::STATUS_APPROVED => '✅ 已核准（照片公開）',
                        Report::STATUS_REJECTED => '已拒絕',
                    ]),
            ])
            ->recordActions([
                Action::make('reject')
                    ->label('拒絕此通報')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Report $record) => ! in_array($record->status, [Report::STATUS_REJECTED]))
                    ->requiresConfirmation()
                    ->action(fn (Report $record) => $record->update(['status' => Report::STATUS_REJECTED])),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
