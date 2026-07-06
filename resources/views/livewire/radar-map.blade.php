<?php

use App\Models\ExternalAlert;
use App\Models\Report;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public function getTickerMessagesProperty(): array
    {
        $messages = [];

        // NCDR 未過期警戒優先播報（涵蓋台北市者由 fetch 端過濾落地）
        ExternalAlert::query()
            ->active()
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
                $label = Report::CATEGORY_LABELS[$category] ?? $category;
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
                $label = Report::CATEGORY_LABELS[$r->category] ?? $r->category;
                $messages[] = '📡 '.$r->created_at->diffForHumans()
                    ."，市民回報{$label}：".Str::limit($r->description, 25);
            });

        return $messages ?: ['📡 系統運作中，等待回報資料…'];
    }

    #[On('report-submitted')]
    public function refreshMap(): void
    {
        // 點位改由前端向 bbox API 取，只需通知 JS 重新抓取
        $this->dispatch('civicshield:reports-updated');
    }
}; ?>

<div
    id="radar-map"
    wire:ignore
    class="absolute inset-0 z-0"
    data-default-lat="{{ config('civicshield.default_lat') }}"
    data-default-lng="{{ config('civicshield.default_lng') }}"
    data-default-zoom="{{ config('civicshield.default_zoom') }}"
    data-reports-url="{{ route('api.map.reports') }}"
    data-shelters-url="{{ route('api.map.shelters') }}"
    data-ticker='@json($this->tickerMessages)'
></div>
