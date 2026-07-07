@extends('layouts.app')

@section('content')
<div class="relative min-h-dvh radar-page">
    {{-- 全螢幕地圖 --}}
    <livewire:radar-map />

    {{-- EOC 戰情室浮動卡片層（自身已 fixed 定位，spec §11.1）--}}
    <livewire:eoc-dashboard />

    {{-- 戰術網格疊層（純 CSS，不干擾地圖互動）--}}
    <div class="pointer-events-none fixed inset-0 z-[1] radar-tactical-grid"></div>

    {{-- 懸浮 UI --}}
    <div class="pointer-events-none fixed inset-0 z-[500] flex flex-col">
        {{-- 頂帶：#002923 實色帶（spec §12.2/12.4）—— banner + nav --}}
        <header class="pointer-events-auto flex items-center justify-between gap-2 bg-[#002923] px-4 py-2 shadow-lg shadow-black/30">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-cyan-400"></span>
                <img src="{{ asset('Hazdar-Banner.png') }}" alt="Hazdar 全民災情回報地圖"
                     class="h-10 w-auto" width="300" height="100">
            </a>

            <nav class="flex items-center gap-2 text-xs">
                @include('partials.locale-switcher')
                <button type="button"
                        onclick="window.dispatchEvent(new CustomEvent('open-contact-form'))"
                        class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">
                    {{ __('聯絡管理員') }}
                </button>
                @auth
                    <a href="{{ route('profile') }}"
                       class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">
                        {{ __('我的回報') }}
                    </a>
                    <a href="{{ route('profile') }}" class="flex items-center gap-2 rounded-full bg-white/10 px-2 py-1.5 hover:bg-white/20">
                        @if (auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="" class="h-5 w-5 rounded-full object-cover">
                        @endif
                        <span class="hidden text-slate-200 sm:inline">{{ auth()->user()->name }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full bg-white/10 px-2 py-2 text-slate-400 hover:text-slate-200">{{ __('登出') }}</button>
                    </form>
                @endauth
            </nav>
        </header>

        {{-- 分類篩選 chips（對應圖例配色；收容所為獨立圖層開關） --}}
        <div class="pointer-events-auto flex flex-wrap gap-1.5 px-4">
            @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                        class="map-chip chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
            @endforeach
            <button type="button" data-map-chip="shelters" style="--chip-color:#4ade80"
                    class="map-chip">🏠 {{ __('收容所') }}</button>
            <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8"
                    class="map-chip chip-on">✅ {{ __('已排除') }}</button>
            <button type="button" data-map-chip="radar_echo" style="--chip-color:#22d3ee"
                    class="map-chip">🌀 {{ __('雷達回波') }}</button>
            @if (config('hazdar.flood_potential.xyz') || config('hazdar.flood_potential.wms_url'))
                <button type="button" data-map-chip="flood_potential" style="--chip-color:#818cf8"
                        class="map-chip">🗺️ {{ __('淹水潛勢') }}</button>
            @endif
        </div>

        <div class="flex-1"></div>

        {{-- 底帶：#002923 實色帶（spec §12.2/12.6）--}}
        <div class="pointer-events-auto flex flex-col gap-3 bg-[#002923] p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-4px_20px_rgba(0,0,0,0.35)]">
            {{-- 管理員公告 --}}
            <livewire:announcement-banner />

            {{-- 我的回報審核結果（已登入才顯示）--}}
            @auth
            <livewire:my-report-status />
            @endauth

            {{-- 跑馬燈廣播（放大到 13px，spec §12.2）--}}
            <div class="mx-auto w-full max-w-md overflow-hidden rounded-full bg-black/30 px-4 py-2">
                <p id="radar-ticker-text"
                   class="radar-ticker truncate text-center text-[13px] font-medium text-slate-100">
                </p>
            </div>

            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('hazdar:report-clicked'))"
                    class="mx-auto flex w-full max-w-md items-center justify-center gap-2 rounded-2xl bg-cyan-600 px-6 py-4 text-base font-bold text-white shadow-[0_0_30px_rgba(8,145,178,0.6)] transition active:scale-95 hover:bg-cyan-500">
                <span class="text-xl">⚠️</span>
                <span>{{ __('立即回報災情') }}</span>
            </button>

            <footer class="mx-auto flex w-full max-w-md flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[11px] text-slate-400">
                {{-- 左下：各分類最新消息篇數（只列有已發佈者，整組連 /news，spec §12.6）--}}
                @if (! empty($newsCounts))
                    <a href="{{ route('news') }}" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 hover:text-slate-200">
                        @foreach ($newsCounts as $cat => $n)
                            <span>{{ __(\App\Models\Announcement::CATEGORY_LABELS[$cat] ?? $cat) }} {{ $n }}</span>
                        @endforeach
                    </a>
                @else
                    <a href="{{ route('news') }}" class="hover:text-slate-200">{{ __('最新消息') }}</a>
                @endif

                <span class="text-slate-600">© {{ date('Y') }} Hazdar</span>
            </footer>
        </div>
    </div>

    {{-- 登入引導 / 回報表單 (Livewire Modal) --}}
    <livewire:auth-onboarding />
    <livewire:report-form />
    <livewire:contact-form />
</div>

@if (session('open_report_form'))
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.dispatch('open-report-form');
        });
    </script>
@endif

<script>
    document.addEventListener('livewire:initialized', () => {
        const canvas = document.getElementById('rain-trend-chart');
        if (! canvas || ! window.Chart) return;

        const trend = JSON.parse(canvas.dataset.trend || '[]');
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: trend.map((t) => t.h),
                datasets: [{
                    data: trend.map((t) => t.v),
                    backgroundColor: trend.map((t) => t.v >= 40 ? '#f87171' : t.v >= 15 ? '#fb923c' : '#38bdf8'),
                    borderRadius: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => `${c.parsed.y} mm` } } },
                scales: {
                    x: { ticks: { color: '#475569', font: { size: 8 }, maxTicksLimit: 12 }, grid: { display: false } },
                    y: { ticks: { color: '#475569', font: { size: 8 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                },
            },
        });
    });
</script>
@endsection
