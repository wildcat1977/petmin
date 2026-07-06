<?php

use App\Models\DashboardResource;
use App\Models\ExternalAlert;
use App\Models\Report;
use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;

new class extends Component
{
    /** 統計卡片顏色/圖示對應（mockup 右欄） */
    public const CATEGORY_CARDS = [
        Report::CATEGORY_FLOOD => ['label' => '積水回報', 'class' => 'text-cyan-300', 'bar' => 'border-cyan-400'],
        Report::CATEGORY_ROAD_BLOCKED => ['label' => '道路中斷', 'class' => 'text-orange-300', 'bar' => 'border-orange-400'],
        Report::CATEGORY_POWER_OUTAGE => ['label' => '停電回報', 'class' => 'text-yellow-300', 'bar' => 'border-yellow-400'],
        Report::CATEGORY_STRUCTURAL_DAMAGE => ['label' => '結構受損', 'class' => 'text-rose-300', 'bar' => 'border-rose-400'],
    ];

    public const SOURCE_LABELS = [
        'cwa_observations' => 'CWA API',
        'ncdr_alerts' => 'NCDR API',
        'radar_echo' => '雷達回波',
        'shelters' => 'DATA.TPE',
    ];

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

    /** 24H 每小時雨量（該小時觀測的 rain_1h 最大值） */
    public function getRainTrendProperty(): array
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
    public function getStatsProperty(): array
    {
        $rows = Report::query()
            ->visible()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw("category, count(*) as day_count, count(case when created_at > ? then 1 end) as hour_count", [now()->subHour()])
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return collect(self::CATEGORY_CARDS)->map(fn ($card, $category) => [
            ...$card,
            'count' => (int) ($rows[$category]->day_count ?? 0),
            'delta' => (int) ($rows[$category]->hour_count ?? 0),
        ])->all();
    }

    /** NCDR 未過期 + 涵蓋台北市警戒（燈號區 + 跑馬燈） */
    public function getAlertsProperty(): array
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

    public function getResourcesProperty(): array
    {
        return DashboardResource::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->label,
                'current' => $r->current_qty,
                'max' => $r->max_qty,
                'unit' => $r->unit,
                'ratio' => $r->max_qty > 0 ? min($r->current_qty / $r->max_qty, 1) : 0,
            ])->all();
    }

    /** footer 真實健康指標（spec §2 設計修正：不做假數據） */
    public function getHealthProperty(): array
    {
        return collect(app(DataSourceHealth::class)->all())
            ->map(fn ($status, $source) => [
                ...$status,
                'label' => self::SOURCE_LABELS[$source] ?? $source,
            ])->all();
    }

    public function getFooterStatsProperty(): array
    {
        return [
            'today_reports' => Report::whereDate('created_at', today())->count(),
            'official' => Report::where('verification_level', Report::VERIFICATION_OFFICIAL)->count(),
            'queue_depth' => $this->queueDepth(),
        ];
    }

    private function queueDepth(): int
    {
        try {
            return (int) Queue::size();
        } catch (\Throwable) {
            return 0; // 測試環境（sync driver）或 Redis 不可用時
        }
    }
}; ?>

<div wire:poll.10s class="flex min-h-dvh flex-col gap-2 p-2 text-slate-100">
    {{-- ── 頂部列 ─────────────────────────────────────────── --}}
    <header class="flex items-center gap-3 rounded-xl border border-white/10 bg-[#0f1522]/90 px-4 py-2">
        <div class="flex items-center gap-2">
            <span class="text-lg">⚠️</span>
            <div>
                <p class="text-sm font-black tracking-[0.25em] text-cyan-300">HAZDAR</p>
                <p class="text-[10px] text-slate-500">全民韌性災害回報系統</p>
            </div>
        </div>

        @if (collect($this->alerts)->contains(fn ($a) => str_contains($a['type'], '颱風')))
            <span class="rounded-md border border-rose-500/70 px-3 py-1 text-xs font-bold tracking-widest text-rose-400 shadow-[0_0_12px_rgba(244,63,94,0.35)]">TYPHOON-EMERGENCY</span>
        @else
            <span class="rounded-md border border-cyan-500/40 px-3 py-1 text-xs font-bold tracking-widest text-cyan-400">MONITORING</span>
        @endif

        {{-- NCDR 跑馬燈 --}}
        <div class="min-w-0 flex-1 overflow-hidden">
            <p id="radar-ticker-text" class="radar-ticker truncate text-xs font-medium text-slate-200">
                @if (count($this->alerts))
                    <span class="font-bold text-rose-400">NCDR</span> {{ $this->alerts[0]['headline'] }}
                @else
                    <span class="font-bold text-emerald-400">NCDR</span> 目前無涵蓋台北市之有效警戒
                @endif
            </p>
        </div>

        <div class="text-right" x-data x-init="setInterval(() => { $refs.clock.textContent = new Date().toLocaleTimeString('en-GB', { timeZone: 'Asia/Taipei' }); }, 1000)">
            <p x-ref="clock" class="font-mono text-xl font-bold tabular-nums text-slate-100">--:--:--</p>
            <p class="font-mono text-[10px] text-slate-500">{{ now()->timezone('Asia/Taipei')->format('Y / m / d') }} TPE</p>
        </div>
    </header>

    {{-- ── 三欄主區 ───────────────────────────────────────── --}}
    <div class="grid flex-1 gap-2 lg:grid-cols-[280px_minmax(0,1fr)_300px]">
        {{-- 左欄：CWA 即時 + 24H 趨勢 + NCDR 燈號 --}}
        <aside class="flex flex-col gap-2">
            <section class="rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
                <p class="text-[10px] font-semibold tracking-widest text-slate-500">CWA 氣象即時數據</p>
                @if ($this->weather)
                    <div class="mt-2 flex items-end gap-2">
                        <span class="text-5xl font-black">{{ $this->weather['temp'] !== null ? round($this->weather['temp']) : '--' }}<span class="text-2xl align-top">°C</span></span>
                        <div class="pb-1 text-xs text-slate-400">
                            <p>臺北測站</p>
                            <p class="text-[10px] text-slate-600">{{ \Illuminate\Support\Carbon::parse($this->weather['observed_at'])->timezone('Asia/Taipei')->format('H:i') }} 觀測</p>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @foreach ([
                            ['label' => '時雨量', 'value' => $this->weather['rain_1h'], 'unit' => 'mm', 'class' => 'text-cyan-300'],
                            ['label' => '風速', 'value' => $this->weather['wind_speed'], 'unit' => 'm/s', 'class' => 'text-emerald-300'],
                            ['label' => '累積雨量 24H', 'value' => $this->rainAccumulated, 'unit' => 'mm', 'class' => 'text-sky-300'],
                            ['label' => '能見度', 'value' => $this->weather['visibility'], 'unit' => 'km', 'class' => 'text-indigo-300'],
                        ] as $stat)
                            <div class="rounded-lg border border-white/10 bg-black/30 p-2">
                                <p class="text-[10px] text-slate-500">{{ $stat['label'] }}</p>
                                <p class="text-xl font-bold {{ $stat['class'] }}">{{ $stat['value'] !== null ? $stat['value'] : '--' }}<span class="ml-0.5 text-[10px] text-slate-500">{{ $stat['unit'] }}</span></p>
                            </div>
                        @endforeach
                    </div>
                @else
                    {{-- 驗收 §1-1：外部源中斷顯示明確狀態，而非壞版面 --}}
                    <div class="mt-3 rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-3 text-xs text-yellow-200">
                        ⚠ CWA 資料更新中斷<br>
                        <span class="text-[10px] text-yellow-200/60">
                            最後更新：{{ data_get($this->health, 'cwa_observations.last_success_at') ? \Illuminate\Support\Carbon::parse(data_get($this->health, 'cwa_observations.last_success_at'))->timezone('Asia/Taipei')->format('m/d H:i') : '尚無成功紀錄' }}
                        </span>
                    </div>
                @endif
            </section>

            <section class="rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
                <p class="text-[10px] font-semibold tracking-widest text-slate-500">24H 降雨趨勢</p>
                <div wire:ignore class="mt-2 h-28">
                    <canvas id="rain-trend-chart" data-trend='@json($this->rainTrend)'></canvas>
                </div>
            </section>

            <section class="flex-1 rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
                <p class="text-[10px] font-semibold tracking-widest text-slate-500">NCDR 示警燈號</p>
                <div class="mt-2 space-y-1.5">
                    @forelse ($this->alerts as $alert)
                        <div class="flex items-center gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-1.5">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ match ($alert['severity']) {
                                'Extreme', 'Severe' => 'bg-rose-400 shadow-[0_0_6px_rgba(244,63,94,0.8)]',
                                'Moderate' => 'bg-yellow-400 shadow-[0_0_6px_rgba(250,204,21,0.8)]',
                                default => 'bg-sky-400',
                            } }}"></span>
                            <span class="text-xs font-semibold">{{ $alert['type'] }}</span>
                            <span class="truncate text-[10px] text-slate-500">{{ $alert['headline'] }}</span>
                        </div>
                    @empty
                        <div class="flex items-center gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-2">
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            <span class="text-xs text-slate-400">目前無涵蓋台北市之有效警戒</span>
                        </div>
                    @endforelse
                </div>
            </section>
        </aside>

        {{-- 中央：情勢地圖（同民眾地圖 bbox API） --}}
        <section class="relative min-h-[420px] overflow-hidden rounded-xl border border-white/10 bg-[#0f1522]/90">
            <livewire:radar-map :show-districts="true" />
            {{-- 圖例 --}}
            <div class="pointer-events-none absolute bottom-3 left-3 z-[500] rounded-lg bg-black/70 px-3 py-2 text-[11px] backdrop-blur">
                <p class="mb-1 font-semibold text-slate-400">圖例</p>
                @foreach ([['#38bdf8', '淹水'], ['#fb923c', '道路中斷'], ['#facc15', '停電'], ['#f87171', '結構受損'], ['#4ade80', '收容所']] as [$color, $label])
                    <p class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background: {{ $color }}"></span>{{ $label }}</p>
                @endforeach
            </div>
        </section>

        {{-- 右欄：事件統計 + 物資看板 --}}
        <aside class="flex flex-col gap-2">
            <section class="rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
                <p class="text-[10px] font-semibold tracking-widest text-slate-500">事件統計 / 即時（近 24H）</p>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    @foreach ($this->stats as $stat)
                        <div class="rounded-lg border-l-2 {{ $stat['bar'] }} border border-white/10 bg-black/30 p-3">
                            <p class="text-3xl font-black {{ $stat['class'] }}">{{ $stat['count'] }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-400">{{ $stat['label'] }}</p>
                            @if ($stat['delta'] > 0)
                                <p class="text-[10px] font-semibold text-rose-400">+{{ $stat['delta'] }} 過去1H</p>
                            @else
                                <p class="text-[10px] text-slate-600">過去1H 無新增</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="flex-1 rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
                <p class="text-[10px] font-semibold tracking-widest text-slate-500">物資 / 民力調度看板</p>
                <div class="mt-2 space-y-3">
                    @forelse ($this->resources as $resource)
                        <div>
                            <div class="flex items-baseline justify-between text-xs">
                                <span class="font-semibold text-slate-200">{{ $resource['label'] }}@if($resource['unit'])<span class="text-slate-500">（{{ $resource['unit'] }}）</span>@endif</span>
                                <span class="font-mono text-slate-400">{{ $resource['current'] }} / {{ $resource['max'] }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-white/10">
                                <div class="h-full rounded-full {{ match (true) {
                                    $resource['ratio'] >= 0.5 => 'bg-emerald-400',
                                    $resource['ratio'] >= 0.25 => 'bg-yellow-400',
                                    default => 'bg-rose-500',
                                } }}" style="width: {{ round($resource['ratio'] * 100) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-600">尚無物資項目（後台可新增）</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- ── Footer：真實系統健康指標（spec §2 設計修正） ───────── --}}
    <footer class="flex flex-wrap items-center gap-x-5 gap-y-1 rounded-xl border border-white/10 bg-[#0f1522]/90 px-4 py-2 text-[11px]">
        @foreach ($this->health as $source)
            <span class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full {{ match ($source['status']) {
                    'green' => 'bg-emerald-400',
                    'yellow' => 'bg-yellow-400 animate-pulse',
                    default => 'bg-rose-500 animate-pulse',
                } }}"></span>
                <span class="text-slate-400">{{ $source['label'] }}</span>
                <span class="font-mono text-slate-600">{{ $source['last_success_at'] ? \Illuminate\Support\Carbon::parse($source['last_success_at'])->timezone('Asia/Taipei')->format('H:i') : '—' }}</span>
            </span>
        @endforeach
        <span class="ml-auto flex items-center gap-4 font-mono text-slate-400">
            <span>今日回報 <b class="text-slate-200">{{ $this->footerStats['today_reports'] }}</b></span>
            <span>官方核實 <b class="text-emerald-300">{{ $this->footerStats['official'] }}</b></span>
            <span>Queue <b class="{{ $this->footerStats['queue_depth'] > 100 ? 'text-yellow-300' : 'text-slate-200' }}">{{ $this->footerStats['queue_depth'] }}</b></span>
        </span>
    </footer>
</div>
