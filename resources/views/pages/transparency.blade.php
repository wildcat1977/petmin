@extends('layouts.app')

@section('title', '審核透明度報告 · Hazdar 災情回報')

@section('content')
@php
    use App\Models\Report;

    $rejectionLabels = Report::REJECTION_REASONS;
    $maxCount = count($stats['rejection_breakdown']) > 0 ? max($stats['rejection_breakdown']) : 1;

    $publicTotal = $stats['approved'];
    $approvalRate = $stats['total'] > 0
        ? round($publicTotal / $stats['total'] * 100)
        : 0;
    $rejectRate = $stats['total'] > 0
        ? round($stats['rejected'] / $stats['total'] * 100)
        : 0;
    $aiRate = $stats['rejected'] > 0
        ? round($stats['ai_auto'] / $stats['rejected'] * 100)
        : 0;

    $avgHrs = round($stats['avg_review_hrs'], 1);
    $avgDisplay = $avgHrs >= 24
        ? round($avgHrs / 24, 1) . ' 天'
        : $avgHrs . ' 小時';

    /*
     * 奇葩退件大賞 - 可在此手動填入圖片與說明文字
     * src: 上傳圖片到 storage/app/public/transparency/ 目錄
     *      再填入 /storage/transparency/xxx.jpg
     */
    $funnyRejections = [
        // 取消下方的 // 即可啟用，圖片請先上傳到 storage/app/public/transparency/
        // ['src' => '/storage/transparency/example.jpg', 'caption' => '範例：使用過往新聞照片，可惜闖關失敗'],
    ];
@endphp

<div class="min-h-dvh bg-[#002923] text-slate-200">

    @include('partials.nav')

    <div class="mx-auto max-w-4xl px-4 py-12">

        {{-- ── Hero ── --}}
        <div class="mb-12 text-center">
            <div class="mb-3 text-5xl">🔍</div>
            <h1 class="mb-3 text-3xl font-bold tracking-tight text-white">審核透明度報告</h1>
            <p class="text-slate-400">公開通報審核數據，讓機制透明可見</p>
            <p class="mt-1 text-xs text-slate-600">資料每小時更新一次 · 截至 {{ now()->setTimezone('Asia/Taipei')->format('Y/m/d H:i') }}</p>
            <p class="mt-3">
                <a href="{{ route('stats') }}"
                   class="inline-flex items-center gap-1.5 rounded-full border border-orange-500/30 bg-orange-500/10 px-4 py-1.5 text-xs text-orange-300 hover:bg-orange-500/20 transition-colors">
                    📊 查看互動式統計地圖 →
                </a>
            </p>
        </div>

        {{-- ── 核心數據卡 ── --}}
        <section class="mb-12">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">📊 核心數據</h2>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">

                <div class="rounded-xl border border-white/10 bg-white/5 p-5 text-center">
                    <div class="text-3xl font-bold text-white">{{ number_format($stats['total']) }}</div>
                    <div class="mt-1 text-xs text-slate-400">累計收到通報</div>
                </div>

                <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-5 text-center">
                    <div class="text-3xl font-bold text-emerald-400">{{ number_format($publicTotal) }}</div>
                    <div class="mt-1 text-xs text-slate-400">審核通過 · {{ $approvalRate }}%</div>
                </div>

                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-5 text-center">
                    <div class="text-3xl font-bold text-red-400">{{ number_format($stats['rejected']) }}</div>
                    <div class="mt-1 text-xs text-slate-400">退件總數 · {{ $rejectRate }}%</div>
                </div>

                <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-5 text-center">
                    <div class="text-3xl font-bold text-amber-400">{{ number_format($stats['ai_auto']) }}</div>
                    <div class="mt-1 text-xs text-slate-400">AI 自動攔截 · 佔退件數 {{ $aiRate }}% </div>
                </div>

                <div class="rounded-xl border border-sky-500/30 bg-sky-500/10 p-5 text-center">
                    <div class="text-3xl font-bold text-sky-400">{{ $avgDisplay }}</div>
                    <div class="mt-1 text-xs text-slate-400">平均審核時間</div>
                </div>

                <div class="rounded-xl border border-violet-500/30 bg-violet-500/10 p-5 text-center">
                    <div class="text-3xl font-bold text-violet-400">{{ number_format($stats['rejected'] - $stats['ai_auto']) }}</div>
                    <div class="mt-1 text-xs text-slate-400">人工退件數</div>
                </div>

            </div>
        </section>

        {{-- ── 通報漏斗 ── --}}
        <section class="mb-12">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">🪣 通報漏斗</h2>
            <div class="space-y-3 rounded-xl border border-white/10 bg-white/5 p-6">
                @php
                    $funnelSteps = [
                        ['label' => '收到通報',  'count' => $stats['total'],    'color' => 'bg-slate-500'],
                        ['label' => 'AI 初篩通過', 'count' => $stats['total'] - $stats['ai_auto'], 'color' => 'bg-sky-600'],
                        ['label' => '人工複審通過', 'count' => $publicTotal + $stats['pending'], 'color' => 'bg-emerald-600'],
                        ['label' => '公開顯示',   'count' => $publicTotal,       'color' => 'bg-emerald-500'],
                    ];
                    $funnelMax = $stats['total'] ?: 1;
                @endphp
                @foreach ($funnelSteps as $step)
                    <div class="flex items-center gap-4">
                        <div class="w-24 shrink-0 text-right text-xs text-slate-400">{{ $step['label'] }}</div>
                        <div class="relative h-7 flex-1 overflow-hidden rounded-md bg-white/10">
                            <div class="{{ $step['color'] }} h-full rounded-md transition-all"
                                 style="width: {{ round($step['count'] / $funnelMax * 100) }}%"></div>
                        </div>
                        <div class="w-12 shrink-0 text-xs font-semibold text-slate-300">{{ number_format($step['count']) }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── 時間趨勢 ── --}}
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

                         // 越舊越暗：opacity 從 0.15（最舊）→ 1.0（最新）
                         const bgColors = data.map((_, i) => {
                             const alpha = n <= 1 ? 1 : 0.15 + (i / (n - 1)) * 0.85;
                             return `rgba(251,146,60,${alpha.toFixed(2)})`;
                         });
                         const borderColors = data.map((_, i) => {
                             const alpha = n <= 1 ? 1 : 0.3 + (i / (n - 1)) * 0.7;
                             return `rgba(251,146,60,${alpha.toFixed(2)})`;
                         });

                         // 每次切換模式都 destroy 重建，避免 axis 狀態不一致
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
                                     borderRadius: 3,
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
                                             font: { size: 10 },
                                             maxRotation: 45,
                                             autoSkip: true,
                                             maxTicksLimit: 12,
                                         },
                                         grid: { color: 'rgba(255,255,255,0.05)' },
                                     },
                                     y: {
                                         ticks: { color: '#94a3b8', font: { size: 10 } },
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
            <div class="mb-5 flex items-center justify-between">
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

            @if (count($stats['weekly']) > 0)
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="relative h-64">
                        <canvas x-ref="canvas"></canvas>
                    </div>
                    <p class="mt-3 text-right text-xs text-slate-600">* 顏色越亮表示越近期，可觀察災情是否有擴散趨勢</p>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-white/20 p-8 text-center text-sm text-slate-500">
                    尚無足夠資料
                </div>
            @endif
        </section>

        {{-- ── 地理時間軸地圖 ── --}}
        @if (count($stats['map_points']) > 0)
        @php
            $mapPointsMin = min(array_column($stats['map_points'], 3));
            $mapPointsMax = max(array_column($stats['map_points'], 3));
        @endphp
        <section class="mb-12"
                 x-data="{
                     points:   {{ Js::from($stats['map_points']) }},
                     minTs:    {{ $mapPointsMin }},
                     maxTs:    {{ $mapPointsMax }},
                     cutoffTs: {{ $mapPointsMax }},
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

                     sliderPct() {
                         if (this.maxTs === this.minTs) return 100;
                         return Math.round((this.cutoffTs - this.minTs) / (this.maxTs - this.minTs) * 100);
                     },

                     initMap() {
                         if (this.map) return;
                         this.map = L.map(this.$refs.mapel, { zoomControl: true, attributionControl: false })
                             .setView([25.045, 121.54], 12);
                         L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                             subdomains: 'abc', maxZoom: 19,
                         }).addTo(this.map);
                         const tilePane = this.map.getPane('tilePane');
                         if (tilePane) tilePane.style.filter = 'invert(100%) hue-rotate(180deg) brightness(0.85) contrast(1.05)';
                         this.renderPoints();
                     },

                     renderPoints() {
                         // 清除舊 layer
                         this.layers.forEach(l => l.remove());
                         this.layers = [];

                         const range = this.maxTs - this.minTs || 1;
                         const categoryHex = @json(\App\Models\ReportCategory::allOrdered()->pluck('color', 'slug'));
                         const hexToRgb = (h) => h ? `${parseInt(h.slice(1,3),16)},${parseInt(h.slice(3,5),16)},${parseInt(h.slice(5,7),16)}` : '148,163,184';
                         const categoryRgb = Object.fromEntries(Object.entries(categoryHex).map(([k, v]) => [k, hexToRgb(v)]));
                         this.points.forEach(([lat, lng, category, ts]) => {
                             if (ts > this.cutoffTs) return;
                             // age 0 = 最舊, 1 = 最新
                             const age = (ts - this.minTs) / range;
                             const alpha = 0.15 + age * 0.85;
                             const color = `rgba(${categoryRgb[category] ?? '148,163,184'},${alpha.toFixed(2)})`;
                             const circle = L.circleMarker([lat, lng], {
                                 radius: 5,
                                 color,
                                 fillColor: color,
                                 fillOpacity: alpha,
                                 weight: 0,
                             });
                             circle.addTo(this.map);
                             this.layers.push(circle);
                         });
                     },

                     onSlider(e) {
                         const pct = e.target.value / 100;
                         this.cutoffTs = Math.round(this.minTs + pct * (this.maxTs - this.minTs));
                         this.renderPoints();
                     },

                     togglePlay() {
                         if (this.playing) {
                             clearInterval(this.playTimer);
                             this.playing = false;
                             return;
                         }
                         // 若已在最大值，從頭開始
                         if (this.cutoffTs >= this.maxTs) {
                             this.cutoffTs = this.minTs;
                             this.renderPoints();
                         }
                         const step = Math.round((this.maxTs - this.minTs) / 80);
                         this.playing = true;
                         this.playTimer = setInterval(() => {
                             this.cutoffTs = Math.min(this.cutoffTs + step, this.maxTs);
                             this.renderPoints();
                             if (this.cutoffTs >= this.maxTs) {
                                 clearInterval(this.playTimer);
                                 this.playing = false;
                             }
                         }, 120);
                     },

                     reset() {
                         clearInterval(this.playTimer);
                         this.playing = false;
                         this.cutoffTs = this.maxTs;
                         this.renderPoints();
                     },
                 }"
                 x-init="$nextTick(() => initMap())">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">🗺️ 災情分佈時間軸</h2>
            <div class="overflow-hidden rounded-xl border border-white/10 bg-white/5">

                {{-- 地圖 --}}
                <div x-ref="mapel" class="h-80 w-full"></div>

                {{-- 控制列 --}}
                <div class="space-y-3 px-4 py-4">
                    {{-- 時間標籤 --}}
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span x-text="tsToLabel(minTs)"></span>
                        <span class="rounded bg-white/10 px-2 py-0.5 text-orange-300 font-medium"
                              x-text="'顯示至 ' + tsToLabel(cutoffTs)"></span>
                        <span x-text="tsToLabel(maxTs)"></span>
                    </div>

                    {{-- 滑桿 --}}
                    <input type="range" min="0" max="100"
                           :value="sliderPct()"
                           @input="onSlider($event)"
                           class="w-full accent-orange-400 cursor-pointer">

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
                        <div class="ml-auto flex items-center gap-3 text-xs text-slate-500">
                            <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-sky-400 mr-1"></span>淹水</span>
                            <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-orange-400 mr-1"></span>道路中斷</span>
                            <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-yellow-400 mr-1"></span>停電</span>
                            <span><span class="inline-block h-2.5 w-2.5 rounded-full bg-red-400 mr-1"></span>結構受損</span>
                            <span class="text-slate-600">越亮 = 越近期</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        @endif

        {{-- ── 退件原因分析 ── --}}
        <section class="mb-12">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">❌ 退件原因分析</h2>
            <div class="space-y-3 rounded-xl border border-white/10 bg-white/5 p-6">
                @forelse ($stats['rejection_breakdown'] as $key => $count)
                    @php
                        $label = $rejectionLabels[$key] ?? $key;
                        $pct   = round($count / $maxCount * 100);
                        $barColor = $key === 'ai_auto' ? 'bg-amber-500' : 'bg-red-600';
                    @endphp
                    <div class="flex items-center gap-3">
                        <div class="w-48 shrink-0 truncate text-right text-xs text-slate-400" title="{{ $label }}">
                            {{ Str::limit($label, 20) }}
                        </div>
                        <div class="relative h-6 flex-1 overflow-hidden rounded-md bg-white/10">
                            <div class="{{ $barColor }} h-full rounded-md" style="width: {{ $pct }}%"></div>
                        </div>
                        <div class="w-12 shrink-0 text-right text-xs font-semibold text-slate-300">{{ $count }}</div>
                    </div>
                @empty
                    <p class="text-center text-sm text-slate-500">尚無退件記錄</p>
                @endforelse
            </div>
            <p class="mt-2 text-right text-xs text-slate-600">* AI 自動攔截 = 圖片不符合規範，由模型自動拒絕</p>
        </section>

        {{-- ── 審核機制 SOP ── --}}
        <section class="mb-12">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">⚙️ 審核機制說明</h2>
            <div class="rounded-xl border border-white/10 bg-white/5 p-6">
                <p class="mb-6 text-sm leading-relaxed text-slate-400">
                    每一筆回報的照片在公開顯示前，都需要經過雙重把關——AI 自動初篩加上人工複審。
                    這樣的機制確保地圖上呈現的是真實、有效的災情資訊。
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @php
                        $sopSteps = [
                            ['icon' => '📤', 'step' => '1', 'title' => '回報提交', 'desc' => '使用者上傳照片、標記位置並填寫說明'],
                            ['icon' => '🤖', 'step' => '2', 'title' => 'AI 初篩',  'desc' => 'Claude AI 分析照片，信心度 ≥ 80% 不符規範時自動退件'],
                            ['icon' => '🧑‍💻', 'step' => '3', 'title' => '人工複審', 'desc' => '志工逐筆審核，驗證照片、位置與說明的一致性'],
                            ['icon' => '✅', 'step' => '4', 'title' => '狀態更新', 'desc' => '通過審核後照片公開顯示於地圖；災情核實與排除由官方另行標記'],
                        ];
                    @endphp
                    @foreach ($sopSteps as $s)
                        <div class="rounded-lg border border-white/10 bg-white/5 p-4">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="text-xl">{{ $s['icon'] }}</span>
                                <span class="text-xs font-semibold text-slate-400">步驟 {{ $s['step'] }}</span>
                            </div>
                            <div class="mb-1 text-sm font-semibold text-white">{{ $s['title'] }}</div>
                            <p class="text-xs leading-relaxed text-slate-500">{{ $s['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── 奇葩退件大賞 ── --}}
        <section class="mb-12">
            <h2 class="mb-2 text-lg font-semibold text-slate-300">🏆 奇葩退件大賞</h2>
            <p class="mb-5 text-sm text-slate-500">精選被 AI 或人工退件的「創意通報」，感謝熱心市民的想像力 🙏</p>

            @if (count($funnyRejections) > 0)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($funnyRejections as $item)
                        <div class="overflow-hidden rounded-xl border border-white/10 bg-white/5">
                            <img src="{{ $item['src'] }}" alt="{{ $item['caption'] }}"
                                 class="aspect-video w-full object-cover opacity-80">
                            <div class="p-3">
                                <p class="text-xs leading-relaxed text-slate-400">{{ $item['caption'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- 尚未加入任何圖片時的佔位區 --}}
                <div class="rounded-xl border border-dashed border-white/20 p-8 text-center">
                    <div class="mb-3 text-4xl">📷</div>
                    <p class="text-sm text-slate-500">
                        精彩案例蒐集中…<br>
                        <span class="text-xs text-slate-600">
                            （開發者提示：在 <code class="text-slate-500">pages/transparency.blade.php</code>
                            的 <code class="text-slate-500">$funnyRejections</code> 陣列加入圖片即可）
                        </span>
                    </p>
                </div>
            @endif
        </section>

        {{-- ── 關於志工 ── --}}
        <section class="mb-12">
            <h2 class="mb-5 text-lg font-semibold text-slate-300">❤️ 關於我們</h2>
            <div class="rounded-xl border border-white/10 bg-white/5 p-6">

                {{--
                ╔════════════════════════════════════════════╗
                ║  以下段落可直接編輯 blade 文字來更新內容   ║
                ╚════════════════════════════════════════════╝
                --}}

                <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 text-center">
                    <div>
                        <div class="text-3xl font-bold text-violet-400">1</div>
                        <div class="mt-1 text-xs text-slate-400">核心開發者</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold text-violet-400">近十位</div>
                        <div class="mt-1 text-xs text-slate-400">熱心市民志工審核</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold text-violet-400">2026</div>
                        <div class="mt-1 text-xs text-slate-400">開始服務但不限於台北市民</div>
                    </div>
                </div>

                <blockquote class="border-l-2 border-violet-500 pl-4 italic text-sm leading-relaxed text-slate-400">
                    「Hazdar 災情回報是一個由市民自發的資訊公開計畫。我們相信透過科技和社群的力量，
                    讓每個人在颱風、豪雨等災害期間都能即時掌握鄰里的災情狀況。每一筆經過審核的回報，
                    都是對社區防災的一份貢獻。」
                    <footer class="mt-2 text-xs text-slate-600 not-italic">— Hazdar 開發者</footer>
                </blockquote>

                <div class="mt-6 rounded-lg border border-amber-500/20 bg-amber-500/5 p-4">
                    <p class="text-xs leading-relaxed text-amber-200/70">
                        💛 本服務純公益經營，不收費、不牟利。若您發現回報有錯誤或有任何建議，
                        歡迎透過聯絡管理員按鈕與我們聯繫。
                    </p>
                </div>

            </div>
        </section>

        {{-- ── 頁腳 ── --}}
        <footer class="border-t border-white/10 pt-6 text-center text-xs text-slate-600">
            <p>Hazdar · 台北市災情回報地圖 · 本頁資料每小時自動更新</p>
            <p class="mt-1">
                <a href="{{ route('home') }}" class="hover:text-slate-400">回地圖</a>
                <span class="mx-2">·</span>
                <a href="{{ route('reports') }}" class="hover:text-slate-400">通報清單</a>
            </p>
        </footer>

    </div>
</div>
@endsection
