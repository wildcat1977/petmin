<?php

namespace App\Filament\Resources\TrashBins\Tables;

use App\Models\TrashBin;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrashBinsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('address')
                    ->label('地點')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('district')
                    ->label('區')
                    ->sortable(),

                BadgeColumn::make('kind')
                    ->label('種類')
                    ->formatStateUsing(fn (string $state) => TrashBin::KIND_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => $state === TrashBin::KIND_DOG_BAG_BOX ? 'info' : 'gray'),

                BadgeColumn::make('status')
                    ->label('狀態')
                    ->formatStateUsing(fn (string $state) => TrashBin::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        TrashBin::STATUS_ACTIVE => 'success',
                        TrashBin::STATUS_MISSING => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('box_count')
                    ->label('數量'),

                TextColumn::make('reports_count')
                    ->label('回報數')
                    ->sortable(),

                TextColumn::make('last_verified_at')
                    ->label('最近驗證')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('—'),

                BadgeColumn::make('source')
                    ->label('來源')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label('種類')->options(TrashBin::KIND_LABELS),
                SelectFilter::make('status')->label('狀態')->options(TrashBin::STATUS_LABELS),
                SelectFilter::make('district')->label('行政區')
                    ->options(fn () => TrashBin::query()->whereNotNull('district')->distinct()->orderBy('district')->pluck('district', 'district')->all()),
            ])
            ->recordActions([
                Action::make('mark_missing')
                    ->label('標記消失')
                    ->color('danger')
                    ->icon('heroicon-o-eye-slash')
                    ->visible(fn (TrashBin $record) => $record->status !== TrashBin::STATUS_MISSING)
                    ->action(fn (TrashBin $record) => $record->update(['status' => TrashBin::STATUS_MISSING, 'last_verified_at' => now()])),
                Action::make('mark_active')
                    ->label('標記使用中')
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->visible(fn (TrashBin $record) => $record->status !== TrashBin::STATUS_ACTIVE)
                    ->action(fn (TrashBin $record) => $record->update(['status' => TrashBin::STATUS_ACTIVE, 'last_verified_at' => now()])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('reports_count', 'desc');
    }
}
