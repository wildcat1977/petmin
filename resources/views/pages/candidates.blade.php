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
            <a href="{{ route('candidates.export') }}"
               class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500">{{ __('↓ 匯出 CSV') }}</a>
        </div>

        @php
            $kindLabels = [
                'mrt' => '捷運', 'bus_stop' => '公車站牌', 'dog_park' => '狗活動區', 'park' => '公園', 'petition' => '陳情',
                'dog_bag_box' => '狗便袋箱', 'report_need_bin' => '市民：需要桶', 'report_full' => '市民：桶滿了', 'report_dumping' => '市民：家戶垃圾', 'existing_bin' => '現存清潔箱',
                'bin_gap' => '缺桶加成',
            ];
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

        {{-- 候選復設點 Top N --}}
        <section class="mt-10">
            <h2 class="text-base font-bold text-slate-100">🔥 {{ __('需求最高的 :n 個網格', ['n' => $candidates->count()]) }}</h2>
            @if ($candidates->isEmpty())
                <p class="mt-3 rounded-xl bg-white/5 p-6 text-center text-sm text-slate-500">{{ __('尚未計算。請執行 php artisan demand:recompute。') }}</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($candidates as $i => $cell)
                        <details class="group rounded-xl border border-white/10 bg-white/5">
                            <summary class="flex cursor-pointer items-center gap-3 px-4 py-3 text-sm">
                                <span class="w-6 text-center font-bold {{ $i < 3 ? 'text-amber-400' : 'text-slate-500' }}">{{ $i + 1 }}</span>
                                <span class="flex-1 truncate text-slate-200">
                                    @php $samples = collect($cell->breakdown)->except('existing_bin')->flatMap(fn ($b) => $b['samples'] ?? [])->unique()->take(3); @endphp
                                    {{ $samples->isNotEmpty() ? $samples->implode('、') : $cell->geohash6 }}
                                </span>
                                <a href="{{ route('home') }}#map={{ $cell->latitude }},{{ $cell->longitude }},16" class="text-[11px] text-cyan-300 hover:underline">{{ __('看地圖') }}</a>
                                <span class="rounded-full bg-orange-500/15 px-2 py-0.5 text-xs font-bold text-orange-300">{{ $cell->score }}</span>
                            </summary>
                            <div class="border-t border-white/10 px-4 py-3 text-xs text-slate-400">
                                <p class="text-slate-500">{{ __('網格 :g（中心 :lat, :lng）', ['g' => $cell->geohash6, 'lat' => number_format($cell->latitude, 5), 'lng' => number_format($cell->longitude, 5)]) }}</p>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($cell->breakdown as $key => $b)
                                        <li class="flex items-center justify-between gap-2">
                                            <span>{{ $kindLabels[$key] ?? $key }} × {{ $b['count'] }}@if (! empty($b['samples']))<span class="text-slate-600">（{{ implode('、', $b['samples']) }}）</span>@endif @if (! empty($b['capped']))<span class="text-slate-600">{{ __('已上限') }}</span>@endif @if (array_key_exists('nearest_m', $b))<span class="text-slate-600">{{ $b['nearest_m'] !== null ? __('（最近清潔箱 :m 公尺）', ['m' => number_format($b['nearest_m'])]) : __('（附近沒有清潔箱）') }}</span>@endif</span>
                                            <span class="{{ $b['score'] < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $b['score'] > 0 ? '+' : '' }}{{ round($b['score'], 1) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif
        </section>

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
                            <span class="text-slate-300">{{ collect($cell->breakdown)->flatMap(fn ($b) => $b['samples'] ?? [])->unique()->take(2)->implode('、') ?: $cell->geohash6 }}</span>
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
