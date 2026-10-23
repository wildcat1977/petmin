<?php

namespace App\Services;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 佔領榜：同一個桶誰的有效回報最多（規則見 config petmin.bin_naming）。
 * 依時間順序逐筆判斷要不要算：同一人距離上一筆「有算到的」不滿冷卻時數不算；
 * 往前一個冷卻時段內已經算了上限人數，也不算。一個桶的回報量很小，每次即時算，不快取。
 */
class BinOccupationService
{
    /**
     * 依有效次數由多到少；同分先達到者在前。
     *
     * @return Collection<int, array{user: User, count: int, reached_at: Carbon}>
     */
    public function standings(TrashBin $bin): Collection
    {
        $config = config('petmin.bin_naming');
        $cooldown = (int) $config['cooldown_hours'] * 3600;
        $maxUsers = (int) $config['max_users_per_window'];

        $reports = Report::query()
            ->where('trash_bin_id', $bin->id)
            ->where('status', Report::STATUS_APPROVED)
            ->where('created_at', '>=', now()->subDays((int) $config['window_days']))
            ->whereHas('user', fn ($q) => $q->where('is_banned', false))
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'user_id', 'created_at']);

        $lastCounted = [];  // user_id => 上一筆有算到的時間戳
        $window = [];       // 最近一個冷卻時段內有算到的時間戳（由舊到新）
        $counts = [];
        $reachedAt = [];

        foreach ($reports as $report) {
            $t = $report->created_at->getTimestamp();
            $uid = $report->user_id;

            if (isset($lastCounted[$uid]) && $t - $lastCounted[$uid] < $cooldown) {
                continue;
            }
            while ($window && $t - $window[0] >= $cooldown) {
                array_shift($window);
            }
            if (count($window) >= $maxUsers) {
                continue;
            }

            $lastCounted[$uid] = $t;
            $window[] = $t;
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            $reachedAt[$uid] = $report->created_at;
        }

        $users = User::whereIn('id', array_keys($counts))->get()->keyBy('id');

        return collect($counts)
            ->map(fn (int $count, int $uid) => ['user' => $users[$uid], 'count' => $count, 'reached_at' => $reachedAt[$uid]])
            ->sort(fn ($a, $b) => [$b['count'], $a['reached_at']] <=> [$a['count'], $b['reached_at']])
            ->values();
    }

    /** 有命名權的人：第一名且達門檻，否則 null */
    public function leader(TrashBin $bin): ?User
    {
        $top = $this->standings($bin)->first();

        return ($top && $top['count'] >= (int) config('petmin.bin_naming.min_reports')) ? $top['user'] : null;
    }
}
