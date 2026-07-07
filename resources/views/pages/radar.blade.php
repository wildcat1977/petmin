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
        {{-- 頂帶：#002923 實色帶（spec §12.2/12.4）—— banner + nav。
             x-data 掛在 header：手機寬度下 nav 塞不下會橫向溢出，改漢堡收合
             （v0.3 三次回饋）。--}}
        <header class="pointer-events-auto bg-[#002923] shadow-lg shadow-black/30"
                x-data="{ mobileNavOpen: false }" @keydown.escape.window="mobileNavOpen = false">
            <div class="flex items-center gap-3 px-4 py-2">
                <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2">
                    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-cyan-400"></span>
                    <img src="{{ asset('Hazdar-Banner.png') }}" alt="Hazdar 全民災情回報地圖"
                         class="h-10 w-auto" width="300" height="100">
                </a>

                {{-- 跑馬燈廣播（移入頂帶中間，v0.3 二次回饋 item 1；放大 13px 沿用 spec §12.2）--}}
                <div class="min-w-0 flex-1 overflow-hidden rounded-full bg-black/30 px-4 py-2">
                    <p id="radar-ticker-text"
                       class="radar-ticker truncate text-center text-[13px] font-medium text-slate-100">
                    </p>
                </div>

                {{-- 桌機選單（lg 以上；手機寬度放不下，改漢堡收合，見下方 mobileNavOpen 面板）--}}
                <nav class="hidden shrink-0 items-center gap-2 text-xs lg:flex">
                    @include('partials.locale-switcher')
                    @include('partials.nav-page-links')

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
                    @else
                        <a href="{{ route('auth.redirect', ['provider' => 'google']) }}"
                           class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">{{ __('登入') }}</a>
                    @endauth
                </nav>

                {{-- 手機：語系切換器 + 漢堡按鈕 --}}
                <div class="flex shrink-0 items-center gap-2 lg:hidden">
                    @include('partials.locale-switcher')
                    <button type="button" @click="mobileNavOpen = !mobileNavOpen"
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 text-slate-200 hover:bg-white/20"
                            :aria-expanded="mobileNavOpen" aria-label="{{ __('選單') }}">
                        <svg x-show="!mobileNavOpen" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileNavOpen" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- 手機展開選單（v0.3 三次回饋：桌機 nav 在手機寬度會溢出，改摺疊面板）--}}
            <div x-show="mobileNavOpen" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="border-t border-white/10 px-4 py-3 lg:hidden">
                <nav class="flex flex-col gap-1 text-sm">
                    <a href="{{ route('news') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('最新消息') }}</a>
                    <a href="{{ route('reports') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('通報清單') }}</a>
                    <a href="{{ route('transparency') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('透明度報告') }}</a>
                    <a href="{{ route('stats') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('統計地圖') }}</a>
                    <button type="button"
                            @click="mobileNavOpen = false; window.dispatchEvent(new CustomEvent('open-contact-form'))"
                            class="rounded-lg px-3 py-2 text-left text-slate-300 hover:bg-white/10">{{ __('聯絡管理員') }}</button>

                    <div class="my-1 border-t border-white/10"></div>

                    @auth
                        <a href="{{ route('profile') }}" @click="mobileNavOpen = false" class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">
                            @if (auth()->user()->avatar)
                                <img src="{{ auth()->user()->avatar }}" alt="" class="h-5 w-5 rounded-full object-cover">
                            @endif
                            {{ __('我的回報') }}
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="w-full rounded-lg px-3 py-2 text-left text-slate-300 hover:bg-white/10">{{ __('登出') }}</button>
                        </form>
                    @else
                        <a href="{{ route('auth.redirect', ['provider' => 'google']) }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('登入') }}</a>
                    @endauth
                </nav>
            </div>
        </header>

        {{-- 分類篩選 chips（對應圖例配色；收容所為獨立圖層開關） --}}
        <div class="pointer-events-auto hidden gap-1.5 px-4 lg:flex lg:flex-wrap">
            @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                        class="map-chip chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
            @endforeach
            <button type="button" data-map-chip="shelters" style="--chip-color:#4ade80"
                    class="map-chip">🏠 {{ __('收容所') }}</button>
            <button type="button" data-map-chip="reported" style="--chip-color:#22d3ee"
                    class="map-chip chip-on">📍 {{ __('回報') }}</button>
            <button type="button" data-map-chip="notified" style="--chip-color:#818cf8"
                    class="map-chip chip-on">📣 {{ __('已通報') }}</button>
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

        {{-- 公告 / 我的回報狀態 / 立即回報鈕：浮在地圖上、各自半透明底（bg-[#111827]/90、backdrop-blur
             等既有樣式），不再共用實色帶——PM 三次回饋：這幾個元件本來就有自己的半透明卡片樣式，
             外面再包一層 #002923 實色帶等於把它們悶成不透明，且不必要地擴大覆蓋地圖的面積。 --}}
        <div class="pointer-events-auto mx-auto flex w-full max-w-md flex-col gap-3 px-4 pb-3">
            <livewire:announcement-banner />

            @auth
            <livewire:my-report-status />
            @endauth

            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('hazdar:report-clicked'))"
                    class="flex items-center justify-center gap-2 rounded-2xl bg-cyan-600 px-6 py-4 text-base font-bold text-white shadow-[0_0_30px_rgba(8,145,178,0.6)] transition active:scale-95 hover:bg-cyan-500">
                <span class="text-xl">⚠️</span>
                <span>{{ __('立即回報災情') }}</span>
            </button>
        </div>

        {{-- 底帶：只剩 footer 這行小字連結 + 燈號，實色帶壓到最薄，減少覆蓋地圖面積（PM 三次回饋）--}}
        <div class="pointer-events-auto bg-[#002923] px-4 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-[0_-2px_10px_rgba(0,0,0,0.35)]">
            <div class="mx-auto w-full max-w-3xl">
                @include('partials.footer-links')
            </div>
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
        function buildRainChart(canvas) {
            const trend = JSON.parse(canvas.dataset.trend || '[]');
            return new window.Chart(canvas, {
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
        }

        // Chart.js 在容器 0 尺寸（卡片收合、modal 未開）時建圖會畫成空白，且容器之後
        // 變可見也不會自動 resize——用 ResizeObserver 觀察容器實際版面大小：首次量到
        // 非 0 尺寸才建圖，之後尺寸變動（卡片展開/modal 開啟）呼叫 resize()（v0.3 二次回饋 item 7）。
        function watchRainChart(id) {
            const canvas = document.getElementById(id);
            if (! canvas || ! window.Chart) return;

            let chart = null;
            new ResizeObserver((entries) => {
                const { width, height } = entries[0].contentRect;
                if (width === 0 || height === 0) return;
                chart ? chart.resize() : (chart = buildRainChart(canvas));
            }).observe(canvas.parentElement);
        }

        watchRainChart('rain-trend-chart');
        watchRainChart('rain-trend-chart-mobile');
    });
</script>
@endsection
