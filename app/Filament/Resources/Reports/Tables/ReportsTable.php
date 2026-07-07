<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('userReports'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('回報者')
                    ->default('訪客')
                    ->searchable([], fn (Builder $query, string $search): Builder => $query
                        ->whereRelation('user', 'name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%"))
                    ->description(fn (Report $record): string => implode(' ', array_filter([
                        $record->user?->is_banned ? '⛔ 已封禁' : null,
                        $record->user_reports_count > 1 ? "共 {$record->user_reports_count} 筆回報" : null,
                        ((float) $record->latitude === Report::DEFAULT_LATITUDE &&
                         (float) $record->longitude === Report::DEFAULT_LONGITUDE)
                            ? '⚠️ 未提供位置授權'
                            : ($record->address
                                ?: ($record->latitude !== null && $record->longitude !== null
                                    ? $record->latitude.', '.$record->longitude
                                    : '—')),
                    ])))
                    ->action(
                        Action::make('view_user')
                            ->modalHeading(fn (Report $record) => ($record->user?->name ?? '使用者').' 的使用者資料')
                            ->modalWidth('7xl')
                            ->modalContent(fn (Report $record) => new HtmlString(
                                '<iframe src="'.htmlspecialchars(UserResource::getUrl('edit', ['record' => $record->user_id]), ENT_QUOTES).'"'
                                .' style="width:100%;height:82vh;border:none;" loading="lazy"></iframe>'
                            ))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('關閉')
                            ->visible(fn (Report $record) => $record->user_id !== null)
                    ),

                BadgeColumn::make('category')
                    ->label('災情分類')
                    ->color(fn (string $state) => match ($state) {
                        Report::CATEGORY_FLOOD => 'info',
                        Report::CATEGORY_ROAD_BLOCKED => 'warning',
                        Report::CATEGORY_POWER_OUTAGE => 'warning',
                        Report::CATEGORY_STRUCTURAL_DAMAGE => 'danger',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state) => ReportCategory::labels()[$state] ?? $state),

                ImageColumn::make('image_path')
                    ->label('照片')
                    ->disk('public')
                    ->height(60)
                    ->width(80)
                    ->url(fn (Report $record) => $record->image_path
                        ? Storage::disk('public')->url($record->image_path)
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('description')
                    ->label('描述')
                    ->wrap()
                    ->searchable()
                    ->default('—'),

                TextColumn::make('address')
                    ->hidden(),

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

                TextColumn::make('extra_value')
                    ->label('補充數值')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rejection_reason')
                    ->label('拒絕原因')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? (Report::REJECTION_REASONS[$state] ?? $state)
                        : '—')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('回報時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('災情分類篩選')
                    ->options(fn () => ReportCategory::labels()),
                SelectFilter::make('status')
                    ->label('狀態篩選')
                    ->options([
                        Report::STATUS_PENDING => '待審核',
                        Report::STATUS_APPROVED => '✅ 已核准（照片公開）',
                        Report::STATUS_REJECTED => '已拒絕',
                    ]),
                SelectFilter::make('verification_level')
                    ->label('核實篩選')
                    ->options(Report::VERIFICATION_LABELS),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('核准（公開照片）')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Report $record) => $record->status === Report::STATUS_PENDING)
                    ->action(fn (Report $record) => $record->update(['status' => Report::STATUS_APPROVED])),

                Action::make('mark_official')
                    ->label('標記官方核實')
                    ->color('success')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (Report $record) => $record->verification_level !== Report::VERIFICATION_OFFICIAL)
                    ->action(fn (Report $record) => $record->update(['verification_level' => Report::VERIFICATION_OFFICIAL])),

                Action::make('mark_notified')
                    ->label('標記已通報')
                    ->color('info')
                    ->icon('heroicon-o-megaphone')
                    ->visible(fn (Report $record) => $record->notified_at === null && $record->resolved_at === null)
                    ->action(fn (Report $record) => $record->update(['notified_at' => now()])),

                Action::make('mark_resolved')
                    ->label('標記已排除')
                    ->color('gray')
                    ->icon('heroicon-o-flag')
                    ->visible(fn (Report $record) => $record->resolved_at === null)
                    ->action(fn (Report $record) => $record->update(['resolved_at' => now()])),

                Action::make('reject')
                    ->label('拒絕')
                    ->color('gray')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Report $record) => $record->status === Report::STATUS_PENDING)
                    ->form([
                        Select::make('rejection_reason')
                            ->label('拒絕原因')
                            ->options(Report::REJECTION_REASONS)
                            ->required()
                            ->native(false)
                            ->helperText('選擇原因方便其他審核人員了解標準'),
                    ])
                    ->action(fn (Report $record, array $data) => $record->update([
                        'status' => Report::STATUS_REJECTED,
                        'rejection_reason' => $data['rejection_reason'],
                    ])),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_all')
                        ->label('批次核准（公開照片）')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('批次核准（公開照片）')
                        ->modalDescription('將所選的待審核回報全部核准（照片公開），非待審核狀態的回報將略過。')
                        ->action(function (Collection $records): void {
                            $records
                                ->filter(fn (Report $record) => $record->status === Report::STATUS_PENDING)
                                ->each(fn (Report $record) => $record->update(['status' => Report::STATUS_APPROVED]));
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('ban_users')
                        ->label('批次封鎖使用者')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('批次封鎖使用者')
                        ->modalDescription('將封鎖所選回報的使用者帳號（訪客回報將略過）。')
                        ->form([
                            Textarea::make('ban_reason')
                                ->label('封禁原因')
                                ->required()
                                ->rows(2)
                                ->placeholder('例：亂傳照片、留言騷擾…'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $userIds = $records
                                ->pluck('user_id')
                                ->filter()
                                ->unique()
                                ->values();

                            if ($userIds->isNotEmpty()) {
                                User::whereIn('id', $userIds)->update([
                                    'is_banned' => true,
                                    'ban_reason' => $data['ban_reason'],
                                ]);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
