<?php

use App\Models\ExternalAlert;
use App\Models\Report;
use App\Models\ReportCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * 全站跑馬燈訊息（v0.3 三次回饋）：從 radar-map 抽出獨立元件，讓次要頁面
 * （profile/reports/news...）不必掛整個地圖也能共用同一份 head nav 跑馬燈。
 * 30s 快取——訊息本來就是 JS 端一次性讀入後由前端輪播，非 live push，
 * 快取不影響觀感，只是省掉每次 page load 都重算三個查詢。
 */
new class extends Component
{
    public function getMessagesProperty(): array
    {
        return Cache::remember('hazdar:site-ticker', 30, function () {
            $messages = [];

            // NCDR 未過期 + 涵蓋台北市之警戒優先播報（spec §5）
            ExternalAlert::query()
                ->active()
                ->coversTaipei()
                ->latest('effective_at')
                ->limit(5)
                ->get(['alert_type', 'headline'])
                ->each(function ($alert) use (&$messages) {
                    $messages[] = '🚨 '.$alert->headline;
                });

            // 近 24h 各分類統計
            Report::query()
                ->visible()
                ->unresolved()
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('category, count(*) as n')
                ->groupBy('category')
                ->pluck('n', 'category')
                ->each(function ($n, $category) use (&$messages) {
                    $label = ReportCategory::labels()[$category] ?? $category;
                    $messages[] = "📊 近 24 小時已收 {$n} 筆{$label}回報";
                });

            // 最近有描述的回報
            Report::query()
                ->visible()
                ->unresolved()
                ->whereNotNull('description')
                ->latest()
                ->limit(6)
                ->get(['category', 'description', 'created_at'])
                ->each(function ($r) use (&$messages) {
                    $label = ReportCategory::labels()[$r->category] ?? $r->category;
                    $messages[] = '📡 '.$r->created_at->diffForHumans()
                        ."，市民回報{$label}：".Str::limit($r->description, 25);
                });

            return $messages ?: ['📡 系統運作中，等待回報資料…'];
        });
    }
}; ?>

<div id="site-ticker" wire:ignore data-ticker='@json($this->messages)'></div>
