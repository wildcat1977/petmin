<?php

use App\Models\ExternalAlert;
use App\Models\InfoLink;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\Shelter;
use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

new class extends Component
{
    /**
     * CWA 最新觀測。Redis 快取為快路徑；快取冷啟（部署/cache:clear/Redis 驅逐）
     * 時退回 DB 最新觀測，避免只因快取空就誤顯示「資料更新中斷」。
     * CWA 測站更新有數十分鐘的發布延遲，觀測本身略舊屬正常，不視為中斷。
     */
    public function getWeatherProperty(): ?array
    {
        if ($cached = Cache::get('hazdar:cwa:latest')) {
            return $cached;
        }

        $obs = WeatherObservation::latest('observed_at')->first();
        if (! $obs) {
            return null;
        }

        return [
            'temp' => $obs->temp,
            'rain_1h' => $obs->rain_1h,
            'wind_speed' => $obs->wind_speed,
            'visibility' => $obs->visibility,
            'station_id' => $obs->station_id,
            'observed_at' => $obs->observed_at->toIso8601String(),
        ];
    }

    /**
     * 匿名訪客共用、每 10s 一次的看板資料（避免公開首頁 wire:poll 對每訪客各打一輪 DB）。
     * 快取只存純陣列/純量——絕不存 Eloquent 物件或 Collection，避免寫入端/讀取端
     * autoload 狀態不一致時反序列化成 __PHP_Incomplete_Class（見 ReportCategory 註解）。
     */
    private function sharedCardData(): array
    {
        return Cache::remember('hazdar:eoc:cards', 10, fn () => [
            'stats' => $this->computeStats(),
            'alerts' => $this->computeAlerts(),
            'infoLinks' => $this->computeInfoLinks(),
            'openShelterCount' => $this->computeOpenShelterCount(),
            'rainTrend' => $this->computeRainTrend(),
        ]);
    }

    /** 24H 每小時雨量（該小時觀測的 rain_1h 最大值） */
    private function computeRainTrend(): array
    {
        $byHour = WeatherObservation::query()
            ->where('observed_at', '>=', now()->subDay())
            ->get(['observed_at', 'rain_1h'])
            ->groupBy(fn ($o) => $o->observed_at->timezone('Asia/Taipei')->format('G'))
            ->map(fn ($group) => round((float) $group->max('rain_1h'), 1));

        $trend = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->timezone('Asia/Taipei')->subHours($i)->format('G');
            $trend[] = ['h' => $hour, 'v' => $byHour[$hour] ?? 0];
        }

        return $trend;
    }

    public function getRainTrendProperty(): array
    {
        return $this->sharedCardData()['rainTrend'];
    }

    /** 24H 累積雨量（每小時雨量加總的近似值） */
    public function getRainAccumulatedProperty(): float
    {
        return round(collect($this->rainTrend)->sum('v'), 1);
    }

    /**
     * 各分類統計卡片（spec §6.3）：近 24h count；+N = 過去 1H。
     * 排除 rejected（內容審核退件），不排除 unverified——災時
     * 「可能有事」的資訊價值大於等核實。
     */
    private function computeStats(): array
    {
        $rows = Report::query()
            ->visible()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('category, count(*) as day_count, count(case when created_at > ? then 1 end) as hour_count', [now()->subHour()])
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return ReportCategory::activeOrdered()->map(fn ($cat) => [
            'slug' => $cat->slug,
            'label' => $cat->name,
            'color' => $cat->color,
            'count' => (int) ($rows[$cat->slug]->day_count ?? 0),
            'delta' => (int) ($rows[$cat->slug]->hour_count ?? 0),
        ])->all();
    }

    public function getStatsProperty(): array
    {
        return $this->sharedCardData()['stats'];
    }

    /** NCDR 未過期 + 涵蓋台北市警戒（燈號區 + 跑馬燈） */
    private function computeAlerts(): array
    {
        return ExternalAlert::query()
            ->active()
            ->coversTaipei()
            ->latest('effective_at')
            ->limit(8)
            ->get(['alert_type', 'headline', 'severity', 'effective_at'])
            ->map(fn ($a) => [
                'type' => $a->alert_type,
                'headline' => $a->headline,
                'severity' => $a->severity,
            ])
            ->all();
    }

    public function getAlertsProperty(): array
    {
        return $this->sharedCardData()['alerts'];
    }

    /**
     * 區塊順序依 SECTION_LABELS 鍵序結構性固定（sort_order 只排區內連結；
     * 後台 reorderable 跨區拖曳會全域改號，不能因此打亂區塊排列）。
     *
     * @return array<string, list<array{label: string, url: string, status_text: ?string}>>
     */
    private function computeInfoLinks(): array
    {
        $grouped = InfoLink::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['section', 'label', 'url', 'status_text'])
            ->groupBy('section');

        // shelter 區塊例外：即使沒有啟用連結也要保留（空陣列），因為
        // 開設中 N 徽章渲染在該區塊標題內——過濾整區會連徽章一起消失（spec §11.5）。
        return collect(array_keys(InfoLink::SECTION_LABELS))
            ->filter(fn ($s) => $s === InfoLink::SECTION_SHELTER || $grouped->has($s))
            ->mapWithKeys(fn ($s) => [$s => $grouped->has($s) ? $grouped[$s]->map(fn ($l) => ['label' => $l->label, 'url' => $l->url, 'status_text' => $l->status_text])->all() : []])
            ->all();
    }

    public function getInfoLinksProperty(): array
    {
        return $this->sharedCardData()['infoLinks'];
    }

    private function computeOpenShelterCount(): int
    {
        return Shelter::query()->where('is_open', true)->count();
    }

    public function getOpenShelterCountProperty(): int
    {
        return $this->sharedCardData()['openShelterCount'];
    }

    /**
     * CWA 資料源最近一次成功時間，僅供資料中斷警告框顯示用（驗收 §1-1）。
     * 完整健康對照表已移至後台 DataSourceHealthWidget（spec §11.1）。
     */
    public function getCwaLastSuccessAtProperty(): ?string
    {
        return app(DataSourceHealth::class)->status('cwa_observations')['last_success_at'];
    }
}; ?>

<div wire:poll.10s>
    {{-- 颱風/監測狀態 pill（保留自舊 header；其餘 header/ticker/health footer 已移除，
         首頁已有自己的品牌 header 與跑馬燈——spec §11.1） --}}
    @php $typhoon = collect($this->alerts)->contains(fn ($a) => str_contains($a['type'], '颱風')); @endphp
    <div class="pointer-events-none fixed left-1/2 top-16 z-[400] -translate-x-1/2">
        <span class="pointer-events-auto rounded-full px-3 py-1 text-[11px] font-bold {{ $typhoon ? 'bg-rose-600/80 text-white animate-pulse' : 'bg-black/60 text-emerald-300' }}">
            {{ $typhoon ? 'TYPHOON-EMERGENCY' : 'MONITORING' }}
        </span>
    </div>

    {{-- 左欄浮動卡片（桌機）：CWA 即時 / 24H 雨量 / NCDR 警戒 --}}
    <div class="pointer-events-none fixed left-2 top-28 z-[400] hidden w-[280px] flex-col gap-2 lg:flex">
        <x-dashboard-card card-key="weather" :title="__('CWA 即時觀測')">
            @include('livewire.partials.card-weather')
        </x-dashboard-card>
        <x-dashboard-card card-key="rain" :title="__('24H 累積雨量')">
            @include('livewire.partials.card-rain', ['withChart' => true])
        </x-dashboard-card>
        <x-dashboard-card card-key="alerts" :title="__('NCDR 警戒')">
            @include('livewire.partials.card-alerts')
        </x-dashboard-card>
    </div>

    {{-- 右欄浮動卡片（桌機）：事件統計 / 交通與清運資訊彙整 --}}
    <div class="pointer-events-none fixed right-2 top-28 z-[400] hidden w-[300px] flex-col gap-2 lg:flex">
        <x-dashboard-card card-key="stats" :title="__('事件統計')">
            @include('livewire.partials.card-stats')
        </x-dashboard-card>
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            @include('livewire.partials.card-info')
        </x-dashboard-card>
    </div>

    {{-- 行動版：底部單欄可捲動卡片抽屜（lg 以下顯示；同五張卡片內容，rain 卡不畫圖避免
         #rain-trend-chart 這個 DOM id 重複——該 id 只在桌機欄渲染一次） --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-24 z-[400] flex max-h-[45vh] flex-col gap-2 overflow-y-auto px-2 lg:hidden">
        <x-dashboard-card card-key="weather" :title="__('CWA 即時觀測')">
            @include('livewire.partials.card-weather')
        </x-dashboard-card>
        <x-dashboard-card card-key="rain" :title="__('24H 累積雨量')">
            @include('livewire.partials.card-rain', ['withChart' => false])
        </x-dashboard-card>
        <x-dashboard-card card-key="alerts" :title="__('NCDR 警戒')">
            @include('livewire.partials.card-alerts')
        </x-dashboard-card>
        <x-dashboard-card card-key="stats" :title="__('事件統計')">
            @include('livewire.partials.card-stats')
        </x-dashboard-card>
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            @include('livewire.partials.card-info')
        </x-dashboard-card>
    </div>
</div>
