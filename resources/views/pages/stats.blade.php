@extends('layouts.app')

@section('title', '回報統計地圖 · Petmin')

@section('content')
@php
    $hasChart = count($stats['weekly']) > 0;
    $hasMap   = count($stats['map_points']) > 0;
    if ($hasMap) {
        $mapPointsMin = min(array_column($stats['map_points'], 3));
        $mapPointsMax = max(array_column($stats['map_points'], 3));
    }
@endphp

<div class="min-h-dvh bg-radar-bg text-slate-200">

    @include('partials.nav')

    <div class="mx-auto max-w-7xl px-4 py-10">

        {{-- ── Hero ── --}}
        <div class="mb-10">
            <h1 class="text-2xl font-bold tracking-tight text-white">📊 通報統計分析</h1>
            <p class="mt-1 text-sm text-slate-500">
                資料每小時更新一次 · 截至 {{ now()->setTimezone('Asia/Taipei')->format('Y/m/d H:i') }}
                · <a href="{{ route('transparency') }}" class="text-slate-400 underline underline-offset-2 hover:text-slate-200">查看完整透明度報告</a>
            </p>
        </div>

        {{-- ── 通報數量趨勢（寬版圖表） ── --}}
        <section class="mb-12"
                 x-data="{
                     mode: 'weekly',
                     chart: null,
                     weekly: {{ Js::from($stats['weekly']) }},
                     daily:  {{ Js::from($stats['daily']) }},
                     init() {
                         this.$nextTick(() => this.buildChart('weekly'));
                     },
                     destroy() {
                         if (this.chart) { this.chart.destroy(); this.chart = null; }
                     },
                     buildChart(mode) {
                         const data = mode === 'weekly' ? this.weekly : this.daily;
                         const n = data.length;
                         const labels = data.map(d => d.label);
                         const totals = data.map(d => d.total);

                         const bgColors = data.map((_, i) => {
                             const alpha = n <= 1 ? 1 : 0.15 + (i / (n - 1)) * 0.85;
                             return `rgba(251,146,60,${alpha.toFixed(2)})`;
                         });
                         const borderColors = data.map((_, i) => {
                             const alpha = n <= 1 ? 1 : 0.3 + (i / (n - 1)) * 0.7;
                             return `rgba(251,146,60,${alpha.toFixed(2)})`;
                         });

                         if (this.chart) {
                             this.chart.destroy();
                             this.chart = null;
                         }

                         const ctx = this.$refs.canvas.getContext('2d');
                         this.chart = new Chart(ctx, {
                             type: 'bar',
                             data: {
                                 labels,
                                 datasets: [{
                                     label: '通報數',
                                     data: totals,
                                     backgroundColor: bgColors,
                                     borderColor: borderColors,
                                     borderWidth: 1,
                                     borderRadius: 4,
                                 }],
                             },
                             options: {
                                 responsive: true,
                                 maintainAspectRatio: false,
                                 plugins: {
                                     legend: { display: false },
                                     tooltip: {
                                         callbacks: {
                                             title: (items) => {
                                                 const label = items[0].label;
                                                 return mode === 'weekly' ? `週起：${label}` : label;
                                             },
                                             label: (item) => ` 通報 ${item.raw} 筆`,
                                         },
                                     },
                                 },
                                 scales: {
                                     x: {
                                         ticks: {
                                             color: '#94a3b8',
                                             font: { size: 11 },
                                             maxRotation: 45,
                                             autoSkip: true,
                                             maxTicksLimit: 20,
                                         },
                                         grid: { color: 'rgba(255,255,255,0.05)' },
                                     },
                                     y: {
                                         ticks: { color: '#94a3b8', font: { size: 11 } },
                                         grid: { color: 'rgba(255,255,255,0.08)' },
                                         beginAtZero: true,
                                     },
                                 },
                             },
                         });
                     },
                     switchMode(m) {
                         this.mode = m;
                         this.buildChart(m);
                     },
                 }">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-300">📈 通報數量趨勢</h2>
                <div class="flex gap-1 rounded-lg border border-white/10 bg-white/5 p-1 text-xs">
                    <button class="rounded-md px-3 py-1 transition-colors"
                            :class="mode === 'weekly' ? 'bg-orange-500/20 text-orange-300' : 'text-slate-400 hover:text-slate-200'"
                            @click="switchMode('weekly')">依週</button>
                    <button class="rounded-md px-3 py-1 transition-colors"
                            :class="mode === 'daily' ? 'bg-orange-500/20 text-orange-300' : 'text-slate-400 hover:text-slate-200'"
                            @click="switchMode('daily')">依日（近 90 天）</button>
                </div>
            </div>

            @if ($hasChart)
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="relative h-80">
                        <canvas x-ref="canvas"></canvas>
                    </div>
                    <p class="mt-3 text-right text-xs text-slate-600">* 顏色越亮表示越近期</p>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-white/20 p-8 text-center text-sm text-slate-500">
                    尚無足夠資料
                </div>
            @endif
        </section>

        {{-- ── 回報分佈時間軸（全寬大地圖） ── --}}
        @if ($hasMap)
        <section class="mb-12"
                 x-data="{
                     points:   {{ Js::from($stats['map_points']) }},
                     minTs:    {{ $mapPointsMin }},
                     maxTs:    {{ $mapPointsMax }},
                     startTs:  {{ $mapPointsMin }},
                     endTs:    {{ $mapPointsMax }},
                     playing:  false,
                     playTimer: null,
                     map: null,
                     layers: [],

                     tsToLabel(ts) {
                         return new Date(ts * 1000).toLocaleDateString('zh-TW', {
                             timeZone: 'Asia/Taipei',
                             month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit',
                         });
                     },

                     pct(ts) {
                         if (this.maxTs === this.minTs) return 100;
                         return Math.round((ts - this.minTs) / (this.maxTs - this.minTs) * 100);
                     },

                     initMap() {
                         if (this.map) return;
                         this.map = L.map(this.$refs.mapel, { zoomControl: true, attributionControl: true, maxZoom: window.__PETMIN_BASEMAP__?.maxZoom() ?? 19 })
                             .setView([25.045, 121.54], 12);
                         // 共用自架底圖的 dark 風格（resources/js/basemap.js）；原本直連 OSM 並以 CSS 反色做暗色。
                         // attribution 打開：ODbL 要求標示出處
                         try {
                             window.__PETMIN_BASEMAP__?.create('dark')?.addTo(this.map);
                         } catch (error) {
                             console.error('basemap failed', error);
                         }
                         setTimeout(() => { this.map.invalidateSize(); this.renderPoints(); }, 50);
                     },

                     renderPoints() {
                         this.layers.forEach(l => l.remove());
                         this.layers = [];
                         const range = this.endTs - this.startTs || 1;
                         const categoryHex = {{ Js::from(\App\Models\ReportCategory::allOrdered()->pluck('color', 'slug')) }};
                         const hexToRgb = (h) => h ? `${parseInt(h.slice(1,3),16)},${parseInt(h.slice(3,5),16)},${parseInt(h.slice(5,7),16)}` : '148,163,184';
                         const categoryRgb = Object.fromEntries(Object.entries(categoryHex).map(([k, v]) => [k, hexToRgb(v)]));
                         this.points.forEach(([lat, lng, category, ts]) => {
                             if (ts < this.startTs || ts > this.endTs) return;
                             const age = (ts - this.startTs) / range;
                             const alpha = 0.15 + age * 0.85;
                             const color = `rgba(${categoryRgb[category] ?? '148,163,184'},${alpha.toFixed(2)})`;
                             const circle = L.circleMarker([lat, lng], {
                                 radius: 6,
                                 color,
                                 fillColor: color,
                                 fillOpacity: alpha,
                                 weight: 0,
                             });
                             circle.addTo(this.map);
                             this.layers.push(circle);
                         });
                     },

                     onStartSlider(e) {
                         const ts = Math.round(this.minTs + (e.target.value / 100) * (this.maxTs - this.minTs));
                         this.startTs = Math.min(ts, this.endTs);
                         this.renderPoints();
                     },

                     onEndSlider(e) {
                         const ts = Math.round(this.minTs + (e.target.value / 100) * (this.maxTs - this.minTs));
                         this.endTs = Math.max(ts, this.startTs);
                         this.renderPoints();
                     },

                     togglePlay() {
                         if (this.playing) {
                             clearInterval(this.playTimer);
                             this.playing = false;
                             return;
                         }
                         if (this.endTs >= this.maxTs) {
                             this.endTs = this.startTs;
                             this.renderPoints();
                         }
                         const step = Math.round((this.maxTs - this.startTs) / 80) || 1;
                         this.playing = true;
                         this.playTimer = setInterval(() => {
                             this.endTs = Math.min(this.endTs + step, this.maxTs);
                             this.renderPoints();
                             if (this.endTs >= this.maxTs) {
                                 clearInterval(this.playTimer);
                                 this.playing = false;
                             }
                         }, 120);
                     },

                     reset() {
                         clearInterval(this.playTimer);
                         this.playing = false;
                         this.startTs = this.minTs;
                         this.endTs   = this.maxTs;
                         this.renderPoints();
                     },
                 }"
                 x-init="$nextTick(() => initMap())">
            <h2 class="mb-4 text-lg font-semibold text-slate-300">🗺️ 回報分佈時間軸</h2>
            <div class="overflow-hidden rounded-xl border border-white/10 bg-white/5">

                {{-- 地圖（全高） --}}
                <div x-ref="mapel" class="h-[60vh] min-h-96 w-full"></div>

                {{-- 控制列 --}}
                <div class="space-y-4 px-5 py-5">

                    {{-- 雙滑桿：起始 + 結束 --}}
                    <div class="space-y-2">
                        {{-- 視覺軌道（顯示選取區間） --}}
                        <div class="relative h-2 rounded-full bg-white/10">
                            <div class="absolute h-full rounded-full bg-orange-400/50"
                                 :style="'left:' + pct(startTs) + '%; right:' + (100 - pct(endTs)) + '%'"></div>
                        </div>

                        {{-- 起始時間拉桿 --}}
                        <div class="flex items-center gap-3">
                            <span class="w-8 shrink-0 text-right text-xs text-slate-500">起</span>
                            <input type="range" min="0" max="100" step="1"
                                   :value="pct(startTs)"
                                   @input="onStartSlider($event)"
                                   class="w-full cursor-pointer accent-orange-400">
                            <span class="w-36 shrink-0 text-xs text-slate-400" x-text="tsToLabel(startTs)"></span>
                        </div>

                        {{-- 結束時間拉桿 --}}
                        <div class="flex items-center gap-3">
                            <span class="w-8 shrink-0 text-right text-xs text-slate-500">迄</span>
                            <input type="range" min="0" max="100" step="1"
                                   :value="pct(endTs)"
                                   @input="onEndSlider($event)"
                                   class="w-full cursor-pointer accent-orange-400">
                            <span class="w-36 shrink-0 text-xs text-orange-300" x-text="tsToLabel(endTs)"></span>
                        </div>
                    </div>

                    {{-- 按鈕列 --}}
                    <div class="flex items-center gap-3">
                        <button @click="togglePlay()"
                                class="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 hover:bg-white/10">
                            <span x-text="playing ? '⏹ 停止' : '▶ 播放'"></span>
                        </button>
                        <button @click="reset()"
                                class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-400 hover:bg-white/10">
                            ↺ 重置
                        </button>
                        <div class="ml-auto flex items-center gap-4 text-xs text-slate-500">
                            @foreach (\App\Models\ReportCategory::activeOrdered() as $legendCat)
                                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $legendCat->color }}"></span>{{ __($legendCat->name) }}</span>
                            @endforeach
                            <span class="text-slate-600">越亮 = 越近期</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        @endif

    </div>

    {{-- ── 頁尾（共用，v0.3 二次回饋 item 6）── --}}
    <div class="border-t border-white/10 px-4 py-6">
        @include('partials.footer-links')
    </div>

</div>
@endsection
