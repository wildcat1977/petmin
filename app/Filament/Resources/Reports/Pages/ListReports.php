<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('全部'),

            'with_photo' => Tab::make('有照片')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('image_path')),

            'pending' => Tab::make('待審核')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Report::STATUS_PENDING)),

            'rejected' => Tab::make('已拒絕')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Report::STATUS_REJECTED)),
        ];
    }

    /**
     * 從 modal 內的 wire:click 呼叫，更新單筆回報狀態（同帳號展開列表用）
     */
    public function updateReportStatus(int $id, string $status, ?string $reason = null): void
    {
        $allowed = [
            Report::STATUS_APPROVED,
            Report::STATUS_REJECTED,
            Report::STATUS_PENDING,
        ];

        if (! in_array($status, $allowed, true)) {
            return;
        }

        $update = ['status' => $status];
        if ($reason !== null && array_key_exists($reason, Report::REJECTION_REASONS)) {
            $update['rejection_reason'] = $reason;
        }

        Report::find($id)?->update($update);
    }
}
