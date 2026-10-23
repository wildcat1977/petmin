<?php

namespace App\Services;

use App\Models\BinName;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 佔領榜：同一個桶誰的有效回報最多（規則見 config petmin.bin_naming）。
 * 依時間順序逐筆判斷要不要算：同一人距離上一筆「有算到的」不滿冷卻時數不算；
 * 往前一個冷卻時段內已經算了上限人數，也不算。一個桶的回報量很小，每次即時算，不快取。
 * 桶被佔領後，命名權看「佔領開始之後」的有效次數（易主一次大家重新累積），見 status()。
 */
class BinOccupationService
{
    /**
     * 依有效次數由多到少；同分先達到者在前。給 $since 時只數那之後的有效回報
     * （冷卻與同時段人數照整段時間判斷，佔領前剛回報過的人，佔領後馬上再回報一樣不算）。
     *
     * @return Collection<int, array{user: User, count: int, reached_at: Carbon}>
     */
    public function standings(TrashBin $bin, ?Carbon $since = null): Collection
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
            if ($since && $report->created_at->lte($since)) {
                continue;
            }
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            $reachedAt[$uid] = $report->created_at;
        }

        $users = User::whereIn('id', array_keys($counts))->get()->keyBy('id');

        return collect($counts)
            ->map(fn (int $count, int $uid) => ['user' => $users[$uid], 'count' => $count, 'reached_at' => $reachedAt[$uid]])
            ->sort(fn ($a, $b) => [$b['count'], $a['reached_at']] <=> [$a['count'], $b['reached_at']])
            ->values();
    }

    /** 目前佔領的起點：名字通過審核的時間（沒記到時退回送出時間） */
    public function occupiedSince(BinName $name): Carbon
    {
        return $name->reviewed_at ?? $name->created_at;
    }

    /**
     * 這個人對這個桶的佔領狀態：
     *   occupier   目前的佔領者
     *   eligible   現在就能佔領命名
     *   waiting    次數夠了，但目前佔領還在保護期，available_at 之後再來
     *   collecting 次數還不夠，left 是還差幾次
     *
     * @return array{state: string, count: int, left: int, available_at: ?Carbon}
     */
    public function status(TrashBin $bin, User $user): array
    {
        $current = $bin->currentName;
        if ($current && $current->user_id === $user->id) {
            return ['state' => 'occupier', 'count' => 0, 'left' => 0, 'available_at' => null];
        }

        $since = $current ? $this->occupiedSince($current) : null;
        $count = (int) ($this->standings($bin, $since)->firstWhere('user.id', $user->id)['count'] ?? 0);
        $left = max(0, (int) config('petmin.bin_naming.min_reports') - $count);
        if ($left > 0) {
            return ['state' => 'collecting', 'count' => $count, 'left' => $left, 'available_at' => null];
        }

        $availableAt = $since?->copy()->addHours((int) config('petmin.bin_naming.protect_hours'));
        if ($availableAt && $availableAt->isFuture()) {
            return ['state' => 'waiting', 'count' => $count, 'left' => 0, 'available_at' => $availableAt];
        }

        return ['state' => 'eligible', 'count' => $count, 'left' => 0, 'available_at' => null];
    }
}
