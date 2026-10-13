<?php

namespace App\Jobs;

use App\Models\Report;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 佐證判定——純 deterministic SQL，不用 AI。
 *
 * 同一個桶（trash_bin_id）或同 geohash7 + 同 category、近 N 小時滑動窗內 distinct user 達門檻
 * → 該群所有 unverified 回報升級為 corroborated。
 * official 只能由後台人工標記，此處不觸碰。
 */
class EvaluateCorroboration implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $geohash7,
        public string $category,
        public ?int $trashBinId = null,
    ) {}

    public function handle(): void
    {
        $windowStart = now()->subHours((int) config('petmin.corroboration.window_hours'));
        $minUsers = (int) config('petmin.corroboration.min_distinct_users');

        $group = Report::query()
            ->where('category', $this->category)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('status', Report::VISIBLE_STATUSES)
            ->when(
                $this->trashBinId,
                fn ($q) => $q->where('trash_bin_id', $this->trashBinId),
                fn ($q) => $q->where('geohash7', $this->geohash7),
            );

        $distinctUsers = (clone $group)->distinct()->count('user_id');

        if ($distinctUsers >= $minUsers) {
            (clone $group)
                ->where('verification_level', Report::VERIFICATION_UNVERIFIED)
                ->update(['verification_level' => Report::VERIFICATION_CORROBORATED]);
        }
    }
}
