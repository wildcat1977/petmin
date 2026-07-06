<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\ImageModerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * 照片 AI 審核（queue 後補，spec §6.1-2）：回報寫入即回應，
 * 審核結果事後更新 status——點位在審核前即上圖，照片通過前不公開。
 *
 *   - 明確違規（is_valid=false 且 confidence ≥ 0.8）→ rejected（點位下圖）
 *   - 通過且吻合分類 → approved（照片公開）
 *   - 不吻合分類或低把握 → 維持 pending 供人工複核，不自動退件（spec §6.2）
 */
class ModerateReportImage implements ShouldQueue
{
    use Queueable;

    /** AI 審核失敗不重試風暴；下次人工審核兜底 */
    public int $tries = 2;

    public function __construct(public int $reportId) {}

    public function handle(ImageModerationService $service): void
    {
        $report = Report::find($this->reportId);

        if (! $report || ! $report->image_path || $report->status !== Report::STATUS_PENDING) {
            return;
        }

        $result = $service->moderate(
            Storage::disk('public')->path($report->image_path),
            $report->category,
        );

        $confidence = (float) ($result['confidence'] ?? 0.0);

        if (! $result['is_valid'] && $confidence >= 0.8) {
            $report->update([
                'status' => Report::STATUS_REJECTED,
                'rejection_reason' => 'ai_auto',
            ]);

            return;
        }

        if ($result['is_valid'] && ($result['matches_category'] ?? true) && $confidence >= 0.8) {
            $report->update(['status' => Report::STATUS_APPROVED]);
        }
        // 其餘情況維持 pending，由後台人工複核
    }
}
