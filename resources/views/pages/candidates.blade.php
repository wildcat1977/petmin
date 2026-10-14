@extends('layouts.app')

@section('title', __('數據判讀 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-200">
    @include('partials.nav')

    <div class="mx-auto max-w-4xl px-4 pb-16 pt-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-100">📊 {{ __('數據判讀') }}</h1>
                <p class="mt-1 text-xs text-slate-500">
                    {{ __('需求分數 =（人流（捷運、公車站牌）+ 遛狗（狗便袋箱、狗活動區）+ 市民回報）× 缺桶加成 − 現存清潔箱；離最近清潔箱 :start 公尺起加成，:full 公尺以上需求加倍。純資料計算，可對照環保局「設置／拆除／遷移評估表」送會勘。', ['start' => config('petmin.demand.bin_gap.start_m'), 'full' => config('petmin.demand.bin_gap.full_m')]) }}
                    @if ($computedAt)
                        <span class="text-slate-600">{{ __('最近重算：:at', ['at' => \Illuminate\Support\Carbon::parse($computedAt)->timezone('Asia/Taipei')->format('m/d H:i')]) }}</span>
                    @endif
                    @if ($insights['synced_at'])
                        <span class="text-slate-600">{{ __('官方桶位同步：:at', ['at' => \Illuminate\Support\Carbon::parse($insights['synced_at'])->timezone('Asia/Taipei')->format('m/d H:i')]) }}</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- 數據判讀：提案論點背後的數字，全部由 open data 即時算出 --}}
        <section class="mt-6 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold text-slate-400">🗑️ {{ __('桶在哪裡') }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-100">{{ $insights['points_near_areas_pct'] }}%</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">{{ __('逐點清單 :n 組清潔箱，:pct% 在「景點商圈」500 公尺內；環保局另有景點商圈清單 :sites 處、:sets 組。桶留在觀光客去的地方。', ['n' => $insights['clean_points'], 'pct' => $insights['points_near_areas_pct'], 'sites' => $insights['area_sites'], 'sets' => $insights['area_sets']]) }}</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold text-slate-400">🐕 {{ __('遛狗族找得到桶嗎') }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-100">{{ $insights['bags_with_bin_100m_pct'] }}%</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">{{ __(':n 個狗便袋箱，只有 :p100% 在 100 公尺內有清潔箱（300 公尺內 :p300%）。狗便袋拿在手上，沒辦法「帶回家」。', ['n' => $insights['bag_boxes'], 'p100' => $insights['bags_with_bin_100m_pct'], 'p300' => $insights['bags_with_bin_300m_pct']]) }}</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold text-slate-400">🏙️ {{ __('各區落差') }}</p>
                @if ($insights['sets_per_bag']['max'] && $insights['sets_per_bag']['min'])
                    @php [$max, $min] = [$insights['sets_per_bag']['max'], $insights['sets_per_bag']['min']]; @endphp
                    <p class="mt-1 text-2xl font-bold text-slate-100">{{ $max['ratio'] }} : {{ $min['ratio'] }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-slate-400">{{ __('每個狗便袋箱分到的清潔箱組數：:max_d :max 組，:min_d 只有 :min 組:times。', ['max_d' => $max['district'], 'max' => $max['ratio'], 'min_d' => $min['district'], 'min' => $min['ratio'], 'times' => $min['ratio'] > 0 ? __('（相差 :x 倍）', ['x' => round($max['ratio'] / $min['ratio'])]) : '']) }}</p>
                @endif
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-semibold text-slate-400">🔥 {{ __('缺桶熱點') }}</p>
                <p class="mt-1 text-2xl font-bold text-slate-100">{{ $hotspots }} <span class="text-sm font-normal text-slate-500">/ {{ $candidateCount }}</span></p>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">
                    {{ __(':n 個候選地點中，:h 個需求分數 ≥ :min。新北市道路沿線垃圾桶已全面取消（', ['n' => $candidateCount, 'h' => $hotspots, 'min' => config('petmin.demand.hotspot_min_score')]) }}<a href="https://news.ltn.com.tw/news/life/breakingnews/5130280" target="_blank" rel="noopener" class="text-cyan-300 hover:underline">{{ __('自由時報') }}</a>{{ __('），地圖照畫作為對照，但只有捷運資料、不列入排行。') }}
                </p>
            </div>
        </section>

        @php
            $kindLabels = \App\Models\DemandCell::BREAKDOWN_LABELS;
        @endphp

        {{-- 有袋沒桶 --}}
        <section class="mt-8">
            <h2 class="text-base font-bold text-slate-100">🧷 {{ __('有袋沒桶') }} <span class="ml-1 rounded-full bg-pink-500/15 px-2 py-0.5 text-xs font-semibold text-pink-300">{{ $gaps->count() }}</span></h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('狗便清潔袋箱周圍 :m 公尺內沒有行人專用清潔箱——袋子裝完沒地方丟。', ['m' => config('petmin.bag_box_gap_meters')]) }}</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach ($gaps->take(12) as $g)
                    <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-200">{{ $g['name'] }}</p>
                            <p class="text-[11px] text-slate-500">{{ $g['district'] }} · {{ __('袋箱 :n 處', ['n' => $g['box_count']]) }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-pink-300">{{ $g['nearest_m'] ? $g['nearest_m'].' m' : '>'.(config('petmin.bag_box_gap_meters') * 3).' m' }}</span>
                    </div>
                @endforeach
            </div>
            @if ($gaps->count() > 12)
                <p class="mt-2 text-xs text-slate-500">{{ __('其餘 :n 處請開地圖「有袋沒桶」圖層。', ['n' => $gaps->count() - 12]) }}</p>
            @endif
        </section>

        {{-- 行政區缺桶排行＋候選地點明細（可篩選排序、CSV 照篩選匯出） --}}
        <livewire:demand-report />

        {{-- 撤除風險 --}}
        <section class="mt-10">
            <h2 class="text-base font-bold text-slate-100">🚫 {{ __('撤除風險（家戶垃圾亂丟集中）') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('這些格子的桶被家戶垃圾塞爆的回報最多，是環保局最可能裁撤的點；建議改加蓋式或加強巡檢，而不是直接拆。') }}</p>
            @if ($dumping->isEmpty())
                <p class="mt-3 text-sm text-slate-600">{{ __('目前沒有已核准的家戶垃圾回報。') }}</p>
            @else
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($dumping as $cell)
                        <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm">
                            <span class="text-slate-300">{{ collect($cell->breakdown)->flatMap(fn ($b) => $b['samples'] ?? [])->unique()->take(2)->implode('、') ?: $cell->cell }}</span>
                            <span class="text-xs font-bold text-rose-300">{{ $cell->dumping_score }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="mt-10 border-t border-white/10 pt-6">
            @include('partials.footer-links')
        </div>
    </div>
</div>
@endsection
