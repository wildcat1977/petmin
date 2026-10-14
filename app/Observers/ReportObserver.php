<?php

namespace App\Observers;

use App\Models\Report;
use App\Models\TrashBin;
use App\Services\BagBoxGapService;

/**
 * 回報核准後的連動（純程式，無 AI）：
 *   - exists  → 桶 status=active、last_verified_at=now
 *   - missing → 桶 status=missing、last_verified_at=now
 *   - full / bag_empty / dumping → 只更新 last_verified_at（桶還在，只是狀況不好）
 *   - 回報者得點、連續天數
 * 只在 status 由非 approved 轉為 approved 時觸發一次，避免重複加點。
 */
class ReportObserver
{
    public function created(Report $report): void
    {
        if ($report->status === Report::STATUS_APPROVED) {
            $this->onApproved($report);
        }
        if ($report->trash_bin_id) {
            TrashBin::whereKey($report->trash_bin_id)->increment('reports_count');
        }
    }

    public function updated(Report $report): void
    {
        if ($report->wasChanged('status')
            && $report->status === Report::STATUS_APPROVED
            && $report->getOriginal('status') !== Report::STATUS_APPROVED) {
            $this->onApproved($report);
        }
    }

    private function onApproved(Report $report): void
    {
        $this->syncBin($report);
        $this->awardPoints($report);
        BagBoxGapService::forgetCache();
    }

    private function syncBin(Report $report): void
    {
        if (! $report->trash_bin_id) {
            return;
        }
        $bin = TrashBin::find($report->trash_bin_id);
        if (! $bin) {
            return;
        }

        $attrs = ['last_verified_at' => now()];
        if ($report->category === Report::CATEGORY_EXISTS) {
            $attrs['status'] = TrashBin::STATUS_ACTIVE;
        } elseif ($report->category === Report::CATEGORY_MISSING) {
            $attrs['status'] = TrashBin::STATUS_MISSING;
        }
        $bin->update($attrs);
    }

    private function awardPoints(Report $report): void
    {
        $user = $report->user;
        if (! $user) {
            return;
        }

        $reportDate = $report->created_at->toDateString();
        $last = $user->last_report_date?->toDateString();
        $streak = match (true) {
            $last === $reportDate => max(1, $user->streak_days),
            $last === $report->created_at->copy()->subDay()->toDateString() => $user->streak_days + 1,
            default => 1,
        };

        $user->forceFill([
            'points' => $user->points + (int) config('petmin.points_per_approved_report'),
            'streak_days' => $streak,
            'last_report_date' => $last === null || $reportDate > $last ? $reportDate : $last,
        ])->save();
    }
}
