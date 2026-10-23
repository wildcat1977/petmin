<?php

namespace App\Filament\Resources\BinNames;

use App\Filament\Resources\BinNames\Pages\ListBinNames;
use App\Models\BinName;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 佔領命名審核：AI 已先審過（通過直接公開、不過退件、AI 失敗停在待審）。
 * 這裡讓管理員處理待審的、或把已公開但不妥的撤下——撤下後地圖自動退回上一個通過的名字。
 */
class BinNameResource extends Resource
{
    protected static ?string $model = BinName::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = '桶名審核';

    protected static ?string $modelLabel = '桶名';

    protected static ?int $navigationSort = 15;

    public static function getNavigationBadge(): ?string
    {
        $pending = BinName::where('status', BinName::STATUS_PENDING)->count();

        return $pending ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['trashBin', 'user']))
            ->columns([
                TextColumn::make('name')->label('名字')->weight('bold')->searchable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        BinName::STATUS_APPROVED => 'success',
                        BinName::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => BinName::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('moderation_note')->label('AI 理由')->default('—')->wrap(),
                TextColumn::make('rejection_reason')
                    ->label('未通過原因')
                    ->formatStateUsing(fn (?string $state) => BinName::REJECTION_REASONS[$state] ?? $state)
                    ->default('—'),
                TextColumn::make('trashBin.address')
                    ->label('桶')
                    ->formatStateUsing(fn ($state, BinName $record) => trim(($record->trashBin?->district ?? '').' '.$state))
                    ->url(fn (BinName $record) => $record->trashBin
                        ? route('bin.checkin', $record->trashBin->external_id ?? $record->trashBin->id)
                        : null, shouldOpenInNewTab: true),
                TextColumn::make('user.name')->label('命名者')->searchable(),
                TextColumn::make('created_at')->label('送出時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('狀態篩選')->options(BinName::STATUS_LABELS),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('公開')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (BinName $record) => $record->status !== BinName::STATUS_APPROVED)
                    ->action(fn (BinName $record) => $record->update([
                        'status' => BinName::STATUS_APPROVED, 'rejection_reason' => null, 'reviewed_at' => now(),
                    ])),
                Action::make('revoke')
                    ->label('撤下')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->visible(fn (BinName $record) => $record->status !== BinName::STATUS_REJECTED)
                    ->action(fn (BinName $record) => $record->update([
                        'status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'admin', 'reviewed_at' => now(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBinNames::route('/'),
        ];
    }
}
