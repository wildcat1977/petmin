<?php

use App\Models\Report;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $filterVerification = '';
    public string $filterCategory     = '';
    public string $search             = '';

    public function updatingFilterVerification(): void
    {
        $this->resetPage();
    }

    public function updatingFilterCategory(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Report::query()
            ->with('user:id,name')
            ->whereNotIn('status', [Report::STATUS_REJECTED, Report::STATUS_PENDING]);

        if ($this->filterVerification) {
            $query->where('verification_level', $this->filterVerification);
        }

        if ($this->filterCategory) {
            $query->where('category', $this->filterCategory);
        }

        if ($this->search) {
            $query->where('description', 'ilike', '%' . $this->search . '%');
        }

        $reports = $query->latest()->paginate(15);

        $pendingReports = Report::query()
            ->with('user:id,name')
            ->where('status', Report::STATUS_PENDING)
            ->latest()
            ->get();

        // 所有可見通報的座標（給地圖 modal 顯示背景點）—— 快取 2 分鐘
        $allCoords = collect(Cache::remember('report_list.all_coords', 120, function () {
            return Report::query()
                ->whereNotIn('status', [Report::STATUS_REJECTED])
                ->get(['id', 'latitude', 'longitude', 'address', 'status'])
                ->toArray();
        }));

        // 分區統計 —— 快取 2 分鐘
        $districtStats = collect(Cache::remember('report_list.district_stats', 120, function () {
            return Report::query()
                ->whereNotIn('status', [Report::STATUS_REJECTED])
                ->whereNotNull('address')
                ->get(['address', 'status', 'resolved_at'])
                ->groupBy(fn($r) => explode(' ', trim($r->address))[1] ?? explode(' ', trim($r->address))[0] ?? '其他')
                ->map(fn($rows) => [
                    'total'    => $rows->count(),
                    'approved' => $rows->where('status', Report::STATUS_APPROVED)->count(),
                    'pending'  => $rows->where('status', Report::STATUS_PENDING)->count(),
                    'resolved' => $rows->whereNotNull('resolved_at')->count(),
                ])
                ->sortByDesc('total')
                ->toArray();
        }));

        return view('livewire.report-list', compact('reports', 'pendingReports', 'allCoords', 'districtStats'));
    }
}; ?>

@php
$verificationConfig = [
    'unverified'   => ['label' => __('未核實'),   'class' => 'bg-slate-500/20 text-slate-400'],
    'corroborated' => ['label' => __('多方佐證'), 'class' => 'bg-sky-500/20 text-sky-400'],
    'official'     => ['label' => __('官方核實'), 'class' => 'bg-green-500/20 text-green-400'],
];
// 分類徽章：由 report_categories 驅動（含停用，舊通報仍需標籤/配色），色票改 inline style
$categoryConfig = \App\Models\ReportCategory::allOrdered()->mapWithKeys(fn ($cat) => [$cat->slug => [
    'label' => __($cat->name),
    'style' => "background: {$cat->color}26; color: {$cat->color}; box-shadow: inset 0 0 0 1px {$cat->color}4d",
]])->all();
$categoryFallback = fn ($category) => $categoryConfig[$category]
    ?? ['label' => $category, 'style' => 'background: rgba(255,255,255,0.1); color: #94a3b8'];
@endphp

<div
    x-data="{
        tab: 'list',
        showMap: false,
        mapLat: 0,
        mapLng: 0,
        mapInfo: '',
        mapId: null,
        _map: null,
        allCoords: @js($allCoords->map(fn($r) => ['id' => $r['id'], 'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'addr' => $r['address'] ?? ''])),

        openMap(id, lat, lng, info) {
            this.mapId   = id;
            this.mapLat  = lat;
            this.mapLng  = lng;
            this.mapInfo = info;
            this.showMap = true;
            this.$nextTick(() => this._initMap());
        },
        closeMap() {
            this.showMap = false;
            if (this._map) { this._map.remove(); this._map = null; }
        },
        _initMap() {
            if (this._map) { this._map.remove(); this._map = null; }
            const map = L.map(this.$refs.modalMapEl, { zoomControl: true, attributionControl: true, maxZoom: window.__PETMIN_BASEMAP__?.maxZoom() ?? 19 })
                .setView([this.mapLat, this.mapLng], 17);
            // 與主地圖共用同一套自架底圖（resources/js/basemap.js）；彈窗固定深色底，用 dark 風格。
            // 沒有 WebGL 時回傳 null，點位照常
            try {
                window.__PETMIN_BASEMAP__?.create('dark')?.addTo(map);
            } catch (error) {
                console.error('basemap failed', error);
            }

            // 其他通報：小灰點
            this.allCoords.forEach(r => {
                if (r.id === this.mapId) return;
                L.circleMarker([r.lat, r.lng], {
                    radius: 5, color: '#94a3b8', fillColor: '#94a3b8',
                    fillOpacity: 0.5, weight: 1
                }).bindTooltip(r.addr || `${r.lat.toFixed(4)}, ${r.lng.toFixed(4)}`, { direction: 'top' })
                  .addTo(map);
            });

            // 目前選中：大紅圈
            L.circleMarker([this.mapLat, this.mapLng], {
                radius: 12, color: '#ef4444', fillColor: '#ef4444',
                fillOpacity: 0.85, weight: 2
            }).bindPopup(`<div style='color:#0f172a;font-size:13px;max-width:200px'>${this.mapInfo}</div>`, { maxWidth: 220 })
              .addTo(map)
              .openPopup();

            this._map = map;
            // 建立當下彈窗還沒排版完成，Leaflet 量到的尺寸是 0×0（底圖與點位全灰、彈出框跑到左上角）；
            // 排版後重新量一次，同統計頁的做法
            setTimeout(() => this._map?.invalidateSize(), 50);
        }
    }"
    class="mx-auto max-w-4xl px-4 py-6"
>

    {{-- 地圖 Modal --}}
    <div x-show="showMap"
         x-cloak
         class="fixed inset-0 z-[2000] flex items-center justify-center bg-black/75 backdrop-blur-sm p-4"
         @keydown.escape.window="closeMap()">
        <div class="relative w-full max-w-2xl rounded-2xl bg-[#161b22] shadow-2xl ring-1 ring-white/10 overflow-hidden"
             @click.outside="closeMap()">
            {{-- Modal 標題列 --}}
            <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                <p class="text-sm font-semibold text-slate-200">
                    📍 <span x-text="mapInfo"></span>
                </p>
                <button @click="closeMap()" class="text-slate-400 hover:text-slate-200">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </div>
            {{-- 地圖本體 --}}
            <div x-ref="modalMapEl" wire:ignore style="height: 400px; z-index: 0;"></div>
            <p class="px-4 py-2 text-center text-[11px] text-slate-500">
                <span class="inline-block h-2 w-2 rounded-full bg-red-500 align-middle"></span> {{ __('本筆通報') }}
                <span class="ml-3 inline-block h-2 w-2 rounded-full bg-slate-400 align-middle"></span> {{ __('其他通報（可縮放比對是否重複）') }}
            </p>
        </div>
    </div>

    {{-- 標題列 --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-slate-100">{{ __('通報紀錄') }}</h2>
        <a href="{{ route('reports.export') }}"
           class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500">
            {{ __('↓ 匯出 CSV') }}
        </a>
    </div>

    {{-- Tab 切換 --}}
    <div class="mb-5 flex gap-1 rounded-xl bg-white/5 p-1">
        <button @click="tab='list'"
                :class="tab==='list' ? 'bg-[#161b22] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'"
                class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition">
            {{ __('通報清單') }}
        </button>
        <button @click="tab='pending'"
                :class="tab==='pending' ? 'bg-[#161b22] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'"
                class="relative flex-1 rounded-lg px-4 py-2 text-sm font-medium transition">
            {{ __('待審核') }}
            @if($pendingReports->count() > 0)
                <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
                    {{ $pendingReports->count() }}
                </span>
            @endif
        </button>
        <button @click="tab='stats'"
                :class="tab==='stats' ? 'bg-[#161b22] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'"
                class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition">
            {{ __('分區統計') }}
        </button>
    </div>

    {{-- 通報清單 tab --}}
    <div x-show="tab==='list'">

    {{-- 篩選列 --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <input wire:model.live.debounce.300ms="search"
               type="search"
               placeholder="{{ __('搜尋說明…') }}"
               class="flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-red-500 min-w-[160px]">

        <select wire:model.live="filterVerification"
                class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-red-500">
            <option value="">{{ __('全部核實程度') }}</option>
            @foreach($verificationConfig as $key => $cfg)
                <option value="{{ $key }}" class="bg-[#161b22]">{{ $cfg['label'] }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterCategory"
                class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-200 focus:outline-none focus:ring-1 focus:ring-red-500">
            <option value="">{{ __('全部分類') }}</option>
            @foreach($categoryConfig as $key => $cfg)
                <option value="{{ $key }}" class="bg-[#161b22]">{{ $cfg['label'] }}</option>
            @endforeach
        </select>
    </div>

    {{-- 資料表格（桌機） --}}
    <div class="hidden overflow-x-auto rounded-xl border border-white/10 sm:block">
        <table class="w-full text-sm">
            <thead class="border-b border-white/10 bg-white/5 text-left text-xs text-slate-400">
                <tr>
                    <th class="w-28 px-4 py-3 font-medium">{{ __('通報時間') }}</th>
                    <th class="w-20 px-4 py-3 font-medium">{{ __('分類') }}</th>
                    <th class="w-32 px-4 py-3 font-medium">{{ __('地點') }}</th>
                    <th class="w-24 px-4 py-3 font-medium">{{ __('核實') }}</th>
                    <th class="w-16 px-4 py-3 font-medium">{{ __('照片') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('說明') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse($reports as $report)
                    <tr class="transition hover:bg-white/5">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-400">
                            {{ $report->created_at->format('Y-m-d') }}<br>
                            <span class="text-xs text-slate-600">{{ $report->created_at->format('H:i') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php $tcfg = $categoryFallback($report->category); @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="{{ $tcfg['style'] }}">
                                {{ $tcfg['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-300">
                            <div class="flex items-start gap-2">
                                <button
                                    @click="openMap({{ $report->id }}, {{ (float)$report->latitude }}, {{ (float)$report->longitude }}, '{{ addslashes($report->address ?? number_format($report->latitude, 4).', '.number_format($report->longitude, 4)) }}')"
                                    title="{{ __('在地圖上查看位置') }}"
                                    class="mt-0.5 shrink-0 text-slate-500 transition hover:text-red-400">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>
                                        <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>
                                        <line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>
                                    </svg>
                                </button>
                                @if($report->address)
                                    {{ $report->address }}
                                @else
                                    <a href="https://maps.google.com/?q={{ $report->latitude }},{{ $report->longitude }}"
                                       target="_blank" rel="noopener"
                                       class="text-slate-500 hover:text-slate-300">
                                        {{ number_format($report->latitude, 4) }}, {{ number_format($report->longitude, 4) }}
                                    </a>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @php $cfg = $verificationConfig[$report->verification_level] ?? ['label' => $report->verification_level, 'class' => 'bg-white/10 text-slate-400']; @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $cfg['class'] }}">
                                {{ $cfg['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if($report->image_path)
                                <a href="{{ Storage::disk('public')->url($report->image_path) }}" target="_blank" rel="noopener">
                                    <img src="{{ Storage::disk('public')->url($report->image_path) }}"
                                         alt="通報照片"
                                         class="h-12 w-12 rounded-lg object-cover ring-1 ring-white/10 transition hover:ring-red-500/60">
                                </a>
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-300">
                            {{ $report->description ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('尚無通報紀錄') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 卡片列表（手機） --}}
    <div class="space-y-3 sm:hidden">
        @forelse($reports as $report)
            @php
                $cfg  = $verificationConfig[$report->verification_level] ?? ['label' => $report->verification_level, 'class' => 'bg-white/10 text-slate-400'];
                $tcfg = $categoryFallback($report->category);
            @endphp
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                {{-- 頂列：時間 + 類型 + 狀態 --}}
                <div class="mb-3 flex items-center justify-between gap-2">
                    <span class="text-xs text-slate-400">
                        {{ $report->created_at->format('Y-m-d H:i') }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="{{ $tcfg['style'] }}">
                            {{ $tcfg['label'] }}
                        </span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $cfg['class'] }}">
                            {{ $cfg['label'] }}
                        </span>
                    </div>
                </div>
                {{-- 地點列 --}}
                <div class="mb-2 flex items-start gap-2 text-sm text-slate-300">
                    <button
                        @click="openMap({{ $report->id }}, {{ (float)$report->latitude }}, {{ (float)$report->longitude }}, '{{ addslashes($report->address ?? number_format($report->latitude, 4).', '.number_format($report->longitude, 4)) }}')"
                        title="{{ __('在地圖上查看位置') }}"
                        class="mt-0.5 shrink-0 text-slate-500 transition hover:text-red-400">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>
                            <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>
                            <line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>
                        </svg>
                    </button>
                    <span>
                        @if($report->address)
                            {{ $report->address }}
                        @else
                            <a href="https://maps.google.com/?q={{ $report->latitude }},{{ $report->longitude }}"
                               target="_blank" rel="noopener"
                               class="text-slate-500 hover:text-slate-300">
                                {{ number_format($report->latitude, 4) }}, {{ number_format($report->longitude, 4) }}
                            </a>
                        @endif
                    </span>
                </div>
                {{-- 照片 + 說明 --}}
                <div class="flex items-start gap-3">
                    @if($report->image_path)
                        <a href="{{ Storage::disk('public')->url($report->image_path) }}" target="_blank" rel="noopener" class="shrink-0">
                            <img src="{{ Storage::disk('public')->url($report->image_path) }}"
                                 alt="通報照片"
                                 class="h-16 w-16 rounded-lg object-cover ring-1 ring-white/10 transition hover:ring-red-500/60">
                        </a>
                    @endif
                    <p class="text-sm leading-relaxed text-slate-300">{{ $report->description ?? '—' }}</p>
                </div>
            </div>
        @empty
            <p class="py-10 text-center text-slate-500">{{ __('尚無通報紀錄') }}</p>
        @endforelse
    </div>

    {{-- 分頁 --}}
    @if($reports->hasPages())
        <div class="mt-4">
            {{ $reports->links() }}
        </div>
    @endif

    {{-- 資料筆數 --}}
    <p class="mt-3 text-right text-xs text-slate-600">
        {{ __('共 :count 筆通報', ['count' => $reports->total()]) }}
    </p>

    </div>{{-- end tab=list --}}

    {{-- 待審核 tab --}}
    <div x-show="tab==='pending'">
        <div class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3">
            <p class="text-sm font-semibold text-amber-300">⏳ {{ __('待審核通報') }}</p>
            <p class="mt-1 text-xs leading-relaxed text-amber-200/70">{{ __('以下回報尚未經管理員審核，資訊未經核實，僅供參考。') }}</p>
        </div>

        @if($pendingReports->isEmpty())
            <p class="py-10 text-center text-slate-500">{{ __('目前沒有待審核的通報') }}</p>
        @else

        {{-- 資料表格（桌機） --}}
        <div class="hidden overflow-x-auto rounded-xl border border-white/10 sm:block">
            <table class="w-full text-sm">
                <thead class="border-b border-white/10 bg-white/5 text-left text-xs text-slate-400">
                    <tr>
                        <th class="w-28 px-4 py-3 font-medium">{{ __('通報時間') }}</th>
                        <th class="w-20 px-4 py-3 font-medium">{{ __('分類') }}</th>
                        <th class="w-32 px-4 py-3 font-medium">{{ __('地點') }}</th>
                        <th class="w-16 px-4 py-3 font-medium">{{ __('照片') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('說明') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach($pendingReports as $report)
                        @php $tcfg = $categoryFallback($report->category); @endphp
                        <tr class="transition hover:bg-white/5">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-400">
                                {{ $report->created_at->format('Y-m-d') }}<br>
                                <span class="text-xs text-slate-600">{{ $report->created_at->format('H:i') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="{{ $tcfg['style'] }}">
                                    {{ $tcfg['label'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                <div class="flex items-start gap-2">
                                    <button
                                        @click="openMap({{ $report->id }}, {{ (float)$report->latitude }}, {{ (float)$report->longitude }}, '{{ addslashes($report->address ?? number_format($report->latitude, 4).', '.number_format($report->longitude, 4)) }}')"
                                        title="{{ __('在地圖上查看位置') }}"
                                        class="mt-0.5 shrink-0 text-slate-500 transition hover:text-red-400">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>
                                            <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>
                                            <line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>
                                        </svg>
                                    </button>
                                    @if($report->address)
                                        {{ $report->address }}
                                    @else
                                        <a href="https://maps.google.com/?q={{ $report->latitude }},{{ $report->longitude }}"
                                           target="_blank" rel="noopener"
                                           class="text-slate-500 hover:text-slate-300">
                                            {{ number_format($report->latitude, 4) }}, {{ number_format($report->longitude, 4) }}
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if($report->image_path)
                                    <a href="{{ Storage::disk('public')->url($report->image_path) }}" target="_blank" rel="noopener">
                                        <img src="{{ Storage::disk('public')->url($report->image_path) }}"
                                             alt="{{ __('回報照片') }}"
                                             class="h-12 w-12 rounded-lg object-cover ring-1 ring-white/10 transition hover:ring-amber-500/60">
                                    </a>
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                {{ $report->description ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- 卡片列表（手機） --}}
        <div class="space-y-3 sm:hidden">
            @foreach($pendingReports as $report)
                @php $tcfg = $categoryFallback($report->category); @endphp
                <div class="rounded-xl border border-amber-500/20 bg-white/5 p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <span class="text-xs text-slate-400">{{ $report->created_at->format('Y-m-d H:i') }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="{{ $tcfg['style'] }}">
                            {{ $tcfg['label'] }}
                        </span>
                    </div>
                    <div class="mb-2 flex items-start gap-2 text-sm text-slate-300">
                        <button
                            @click="openMap({{ $report->id }}, {{ (float)$report->latitude }}, {{ (float)$report->longitude }}, '{{ addslashes($report->address ?? number_format($report->latitude, 4).', '.number_format($report->longitude, 4)) }}')"
                            title="{{ __('在地圖上查看位置') }}"
                            class="mt-0.5 shrink-0 text-slate-500 transition hover:text-red-400">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>
                                <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>
                                <line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>
                            </svg>
                        </button>
                        <span>
                            @if($report->address)
                                {{ $report->address }}
                            @else
                                <a href="https://maps.google.com/?q={{ $report->latitude }},{{ $report->longitude }}"
                                   target="_blank" rel="noopener"
                                   class="text-slate-500 hover:text-slate-300">
                                    {{ number_format($report->latitude, 4) }}, {{ number_format($report->longitude, 4) }}
                                </a>
                            @endif
                        </span>
                    </div>
                    <div class="flex items-start gap-3">
                        @if($report->image_path)
                            <a href="{{ Storage::disk('public')->url($report->image_path) }}" target="_blank" rel="noopener" class="shrink-0">
                                <img src="{{ Storage::disk('public')->url($report->image_path) }}"
                                     alt="{{ __('回報照片') }}"
                                     class="h-16 w-16 rounded-lg object-cover ring-1 ring-white/10 transition hover:ring-amber-500/60">
                            </a>
                        @endif
                        <p class="text-sm leading-relaxed text-slate-300">{{ $report->description ?? '—' }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-right text-xs text-slate-600">
            {{ __('共 :count 筆通報', ['count' => $pendingReports->count()]) }}
        </p>

        @endif
    </div>{{-- end tab=pending --}}

    {{-- 分區統計 tab --}}
    @php
        $districts   = $districtStats->keys()->values()->toArray();
        $totals      = $districtStats->pluck('total')->values()->toArray();
        $chartColors = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#a855f7','#ec4899','#14b8a6','#f59e0b','#6366f1','#84cc16','#06b6d4','#d946ef','#64748b','#e11d48'];
        $totalAll    = array_sum($totals);
    @endphp
    <div x-show="tab==='stats'"
         x-init="
            $watch('tab', v => {
                if (v !== 'stats') return;
                $nextTick(() => {
                    const canvas = document.getElementById('district-pie');
                    if (!canvas || canvas._chartInstance) return;
                    const chart = new Chart(canvas, {
                        type: 'pie',
                        data: {
                            labels: @js($districts),
                            datasets: [{
                                data: @js($totals),
                                backgroundColor: @js(array_slice($chartColors, 0, count($districts))),
                                borderColor: '#0d1117',
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: ctx => ` ${ctx.label}：${ctx.parsed} 筆（${((ctx.parsed / {{ $totalAll ?: 1 }}) * 100).toFixed(1)}%）`
                                    }
                                }
                            }
                        }
                    });
                    canvas._chartInstance = chart;
                });
            });
         ">

        @if($districtStats->isEmpty())
            <p class="py-16 text-center text-slate-500">{{ __('尚無具有地址的通報') }}</p>
        @else
        {{-- 圓餅圖 --}}
        <div class="mb-6 flex justify-center">
            <div class="w-full max-w-xs">
                <canvas id="district-pie"></canvas>
            </div>
        </div>

        {{-- 統計列表 --}}
        <div class="overflow-x-auto rounded-xl border border-white/10">
            <table class="w-full text-sm">
                <thead class="border-b border-white/10 bg-white/5 text-left text-xs text-slate-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('行政區') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ __('通報數') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ __('已核准') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ __('待審核') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ __('已排除') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('佔比') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach($districtStats as $district => $stat)
                    @php $pct = $totalAll > 0 ? round($stat['total'] / $totalAll * 100, 1) : 0; @endphp
                    <tr class="transition hover:bg-white/5">
                        <td class="px-4 py-3 font-medium text-slate-200">{{ $district }}</td>
                        <td class="px-4 py-3 text-right text-slate-100">{{ $stat['total'] }}</td>
                        <td class="px-4 py-3 text-right text-green-400">{{ $stat['approved'] }}</td>
                        <td class="px-4 py-3 text-right text-amber-400">{{ $stat['pending'] }}</td>
                        <td class="px-4 py-3 text-right text-slate-400">{{ $stat['resolved'] }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10">
                                    <div class="h-full rounded-full bg-red-500" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="w-10 text-right text-xs text-slate-400">{{ $pct }}%</span>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-white/10 bg-white/5">
                    <tr>
                        <td class="px-4 py-3 text-xs font-semibold text-slate-400">{{ __('合計') }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-100">{{ $totalAll }}</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif

    </div>{{-- end tab=stats --}}
</div>{{-- end x-data --}}
