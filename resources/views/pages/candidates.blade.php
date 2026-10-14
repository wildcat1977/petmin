@extends('layouts.app')

@section('title', __('候選復設點 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-200">
    @include('partials.nav')

    <div class="mx-auto max-w-4xl px-4 pb-16 pt-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-100">📋 {{ __('候選復設點') }}</h1>
                <p class="mt-1 text-xs text-slate-500">
                    {{ __('需求分數 =（人流（捷運、公車站牌）+ 遛狗（狗便袋箱、狗活動區）+ 市民回報）× 缺桶加成 − 現存清潔箱；離最近清潔箱 :start 公尺起加成，:full 公尺以上需求加倍。純資料計算，可對照環保局「設置／拆除／遷移評估表」送會勘。', ['start' => config('petmin.demand.bin_gap.start_m'), 'full' => config('petmin.demand.bin_gap.full_m')]) }}
                    @if ($computedAt)
                        <span class="text-slate-600">{{ __('最近重算：:at', ['at' => \Illuminate\Support\Carbon::parse($computedAt)->timezone('Asia/Taipei')->format('m/d H:i')]) }}</span>
                    @endif
                </p>
            </div>
        </div>

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
