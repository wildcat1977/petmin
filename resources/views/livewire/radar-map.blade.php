<?php

use App\Models\ExternalAlert;
use App\Models\Report;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** EOC dashboard 開啟行政區界疊層 */
    public bool $showDistricts = false;

    public function getTickerMessagesProperty(): array
    {
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
                $label = \App\Models\ReportCategory::labels()[$category] ?? $category;
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
                $label = \App\Models\ReportCategory::labels()[$r->category] ?? $r->category;
                $messages[] = '📡 '.$r->created_at->diffForHumans()
                    ."，市民回報{$label}：".Str::limit($r->description, 25);
            });

        return $messages ?: ['📡 系統運作中，等待回報資料…'];
    }

    #[On('report-submitted')]
    public function refreshMap(): void
    {
        // 點位改由前端向 bbox API 取，只需通知 JS 重新抓取
        $this->dispatch('hazdar:reports-updated');
    }
}; ?>

<div
    id="radar-map"
    wire:ignore
    class="absolute inset-0 z-0"
    data-default-lat="{{ config('hazdar.default_lat') }}"
    data-default-lng="{{ config('hazdar.default_lng') }}"
    data-default-zoom="{{ config('hazdar.default_zoom') }}"
    data-reports-url="{{ route('api.map.reports') }}"
    data-shelters-url="{{ route('api.map.shelters') }}"
    data-radar-echo-url="{{ route('api.radar-echo') }}"
    data-radar-echo-bounds='@json(config('hazdar.sources.radar_echo.bounds'))'
    data-flood-potential='@json(config('hazdar.flood_potential'))'
    @if ($showDistricts) data-districts-url="{{ asset('geo/taipei-districts.json') }}" @endif
    data-ticker='@json($this->tickerMessages)'
></div>
