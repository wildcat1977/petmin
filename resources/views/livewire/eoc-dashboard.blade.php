<?php

use App\Models\ExternalAlert;
use App\Models\PromoCard;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use App\Support\CityInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
            'cityInfoSummary' => $this->computeCityInfoSummary(),
            'rainTrend' => $this->computeRainTrend(),
            'promoCards' => $this->computePromoCards(),
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
     * 首頁卡「交通與清運資訊彙整」parsed 摘要（Task 6 起取代逐區塊連結清單，
     * 完整內容改置於 /info；共用 App\Support\CityInfo 作為單一資料源）。
     *
     * @return array{runningPumps: int, closureActive: bool, openShelters: int, metroStatus: ?int}
     */
    private function computeCityInfoSummary(): array
    {
        return [
            'runningPumps' => CityInfo::runningPumpCount(),
            'closureActive' => CityInfo::closure() !== null,
            'openShelters' => CityInfo::openShelterCount(),
            'metroStatus' => CityInfo::metroOverallStatus(),
        ];
    }

    public function getCityInfoSummaryProperty(): array
    {
        return $this->sharedCardData()['cityInfoSummary'];
    }

    /**
     * 啟用中圖卡（spec §12.5）：只存純陣列（title/image_url），避免快取 Eloquent 物件。
     *
     * @return list<array{title: string, image_url: string}>
     */
    private function computePromoCards(): array
    {
        return PromoCard::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['title', 'image_path'])
            ->map(fn (PromoCard $c) => [
                'title' => $c->title,
                'image_url' => Storage::disk('public')->url($c->image_path),
            ])
            ->all();
    }

    public function getPromoCardsProperty(): array
    {
        return $this->sharedCardData()['promoCards'];
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
    {{-- 左欄浮動卡片（桌機）：CWA 即時 / 24H 雨量 / NCDR 警戒
         top-36（v0.3 二次回饋 item 3）：實測地圖明暗切換按鈕於 top-28 時會與本卡重疊
         （按鈕 y=[102,132.8]，卡片原 top-28=112 提前於按鈕結束前就開始），下移淨空。 --}}
    <div class="pointer-events-none fixed left-2 top-36 z-[400] hidden w-[280px] flex-col gap-2 lg:flex">
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

    {{-- 右欄浮動卡片（桌機）：事件統計 / 交通與清運資訊彙整（top-36 同左欄理由）--}}
    <div class="pointer-events-none fixed right-2 top-36 z-[400] hidden w-[300px] flex-col gap-2 lg:flex">
        <x-dashboard-card card-key="stats" :title="__('事件統計')">
            @include('livewire.partials.card-stats')
        </x-dashboard-card>
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            @include('livewire.partials.card-info')
        </x-dashboard-card>
        @include('livewire.partials.card-promo')
    </div>

    {{-- 行動版右側 IITC 圖層/資訊面板（spec §12.7）：取代舊底部抽屜 --}}
    <div class="lg:hidden" x-data="{ panelOpen: false, activeCard: null }">
        {{-- 右緣收合 tab --}}
        <button type="button" @click="panelOpen = ! panelOpen"
                class="pointer-events-auto fixed right-0 top-1/2 z-[510] -translate-y-1/2 rounded-l-lg bg-radar-bg px-1.5 py-5 text-lg text-slate-200 shadow-lg shadow-black/40"
                :aria-expanded="panelOpen">
            <span x-text="panelOpen ? '›' : '‹'"></span>
        </button>

        {{-- 面板 --}}
        <div x-show="panelOpen" x-cloak x-transition.origin.right
             class="pointer-events-auto fixed bottom-24 right-0 top-16 z-[505] flex w-64 flex-col gap-3 overflow-y-auto rounded-l-xl border-l border-white/10 bg-radar-bg/98 p-3 backdrop-blur">
            {{-- 圖層/狀態 checkbox 群組 wire:ignore（spec §12.7 3d 修正）：
                 勾選狀態由 initChips（app.js）純前端管理，wire:poll.10s 每次 morph
                 會把 DOM 蓋回本檔的預設 class，把使用者剛關閉的 chip 打勾狀態還原。
                 這兩組清單資料是 session 內靜態的（分類/圖層來源不隨 poll 變動），
                 不需要每次 poll 重新渲染，故整組隔離安全。 --}}
            <div wire:ignore>
                {{-- 圖層 --}}
                <div>
                    <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('圖層') }}</p>
                    <div class="flex flex-col gap-1">
                        @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                            <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                                    class="layer-check chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
                        @endforeach
                        <button type="button" data-map-chip="shelters" style="--chip-color:#4ade80" class="layer-check">🏠 {{ __('收容所') }}</button>
                        <button type="button" data-map-chip="radar_echo" style="--chip-color:#22d3ee" class="layer-check">🌀 {{ __('雷達回波') }}</button>
                        @if (config('hazdar.flood_potential.xyz') || config('hazdar.flood_potential.wms_url'))
                            <button type="button" data-map-chip="flood_potential" style="--chip-color:#818cf8" class="layer-check">🗺️ {{ __('淹水潛勢') }}</button>
                        @endif
                    </div>
                </div>
                {{-- 狀態三態 --}}
                <div>
                    <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('狀態') }}</p>
                    <div class="flex flex-col gap-1">
                        <button type="button" data-map-chip="reported" style="--chip-color:#22d3ee" class="layer-check chip-on">📍 {{ __('回報') }}</button>
                        <button type="button" data-map-chip="notified" style="--chip-color:#818cf8" class="layer-check chip-on">📣 {{ __('已通報') }}</button>
                        <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8" class="layer-check chip-on">✅ {{ __('已排除') }}</button>
                    </div>
                </div>
            </div>
            {{-- 資訊卡按鈕 --}}
            <div>
                <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('資訊') }}</p>
                <div class="flex flex-col gap-1">
                    <button type="button" @click="activeCard = 'weather'" class="card-open-btn">{{ __('CWA 即時觀測') }}</button>
                    <button type="button" @click="activeCard = 'rain'" class="card-open-btn">{{ __('24H 累積雨量') }}</button>
                    <button type="button" @click="activeCard = 'alerts'" class="card-open-btn">{{ __('NCDR 警戒') }}</button>
                    <button type="button" @click="activeCard = 'stats'" class="card-open-btn">{{ __('事件統計') }}</button>
                    <button type="button" @click="activeCard = 'info'" class="card-open-btn">{{ __('交通與清運資訊彙整') }}</button>
                </div>
            </div>
            {{-- 圖卡 banner（spec §12.5，行動版改置於面板內）--}}
            @include('livewire.partials.card-promo')
        </div>

        {{-- 卡片 bottom-sheet modals --}}
        @php
            $mobileCards = [
                'weather' => ['CWA 即時觀測', 'livewire.partials.card-weather', []],
                'rain' => ['24H 累積雨量', 'livewire.partials.card-rain', ['withChart' => true, 'chartId' => 'rain-trend-chart-mobile']],
                'alerts' => ['NCDR 警戒', 'livewire.partials.card-alerts', []],
                'stats' => ['事件統計', 'livewire.partials.card-stats', []],
                'info' => ['交通與清運資訊彙整', 'livewire.partials.card-info', []],
            ];
        @endphp
        @foreach ($mobileCards as $key => [$cardTitle, $partial, $partialData])
            <div x-show="activeCard === '{{ $key }}'" x-cloak @click.self="activeCard = null"
                 class="pointer-events-auto fixed inset-0 z-[550] flex items-end bg-black/50">
                <div class="max-h-[75dvh] w-full overflow-y-auto rounded-t-2xl bg-radar-card p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-200">{{ __($cardTitle) }}</h3>
                        <button type="button" @click="activeCard = null" class="text-lg text-slate-400 hover:text-slate-200">✕</button>
                    </div>
                    @include($partial, $partialData)
                </div>
            </div>
        @endforeach
    </div>
</div>
