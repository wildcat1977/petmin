@extends('layouts.app')

@push('head')
    {{-- 底圖 style 預先連線並依使用者上次選的主題預載（MapLibre 以 CORS 匿名 fetch，須帶 crossorigin） --}}
    <link rel="preconnect" href="{{ parse_url(config('petmin.map.basemap_styles.dark'), PHP_URL_SCHEME).'://'.parse_url(config('petmin.map.basemap_styles.dark'), PHP_URL_HOST) }}" crossorigin>
    <script>
        (() => {
            let theme = 'light';
            // 與 app.js 的 MAP_THEME_KEY 相同
            try { theme = localStorage.getItem('petmin_map_theme') || 'light'; } catch (_) {}
            const link = document.createElement('link');
            link.rel = 'preload';
            link.as = 'fetch';
            link.crossOrigin = 'anonymous';
            link.href = window.__PETMIN_BASEMAP_CONFIG__.styles[theme] ?? window.__PETMIN_BASEMAP_CONFIG__.styles.light;
            document.head.appendChild(link);
        })();
    </script>
@endpush

@section('content')
<div class="relative min-h-dvh radar-page">
    {{-- 全螢幕地圖 --}}
    <livewire:radar-map />
    {{-- 沒有 WebGL 時 app.js 取消隱藏（docs/basemap.md）；點位與圖層照常 --}}
    <p data-basemap-unsupported class="pointer-events-none fixed inset-x-0 top-40 z-[501] mx-auto hidden w-fit max-w-[80%] rounded-lg bg-slate-900/90 px-3 py-1.5 text-center text-xs text-slate-200 shadow">
        {{ __('此瀏覽器無法顯示地圖底圖，桶位與回報仍會照常顯示。') }}
    </p>

    {{-- 懸浮 UI --}}
    <div class="pointer-events-none fixed inset-0 z-[500] flex flex-col">
        {{-- 頂帶：全站共用 head nav --}}
        @include('partials.site-header')

        {{-- 桌機：右上「圖層」鈕，滑過展開 checkbox 面板（IITC 風格，地圖不被一整排按鈕佔掉）；手機收進右側面板 --}}
        <div data-map-chip-bar class="pointer-events-none hidden justify-end px-4 lg:flex">
            <div class="pointer-events-auto relative" x-data="{ open: false, timer: null }"
                 @mouseenter="clearTimeout(timer); open = true"
                 @mouseleave="timer = setTimeout(() => open = false, 250)">
                <button type="button" @click="open = ! open" :aria-expanded="open"
                        class="flex items-center gap-1.5 rounded-lg border border-white/15 bg-radar-bg/95 px-3 py-1.5 text-sm font-semibold text-slate-200 shadow-lg shadow-black/40 backdrop-blur hover:border-white/30">
                    🗂️ {{ __('圖層') }} <span class="text-xs text-slate-400" x-text="open ? '▴' : '▾'"></span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                     class="layer-panel absolute right-0 top-full z-[520] mt-1 max-h-[70vh] w-[30rem] overflow-y-auto rounded-xl border border-white/10 bg-radar-bg/97 p-3 shadow-2xl shadow-black/50 backdrop-blur">
                    @include('partials.map-layer-chips')
                </div>
            </div>
        </div>

        <div class="flex-1"></div>

        {{-- 公告 / 我的回報狀態 / 立即回報鈕 --}}
        <div class="pointer-events-auto mx-auto flex w-full max-w-md flex-col gap-3 px-4 pb-3">
            <livewire:announcement-banner />

            @auth
            <livewire:my-report-status />
            @endauth

            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('petmin:report-clicked'))"
                    class="flex items-center justify-center gap-2 rounded-2xl bg-amber-500 px-6 py-4 text-base font-bold text-stone-900 shadow-[0_0_30px_rgba(245,158,11,0.45)] transition active:scale-95 hover:bg-amber-400">
                <span class="text-xl">🐾</span>
                <span>{{ __('回報垃圾桶狀況') }}</span>
            </button>
        </div>

        {{-- 底帶：footer 小字連結 + 底圖版權 --}}
        <div class="pointer-events-auto bg-radar-bg px-4 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-[0_-2px_10px_rgba(0,0,0,0.35)]">
            <div class="mx-auto w-full max-w-3xl">
                @include('partials.footer-links')
            </div>
            <div class="mx-auto mt-1 flex w-full max-w-3xl items-center gap-2 text-[10px] text-slate-500 [&_a]:underline [&_a]:hover:text-slate-300">
                <span class="truncate">{!! config('petmin.map.basemap_attribution') !!}</span>
            </div>
        </div>
    </div>

    {{-- 行動版右側圖層面板：checkbox 式圖層列，狀態由 app.js initChips 純前端管理 --}}
    <div class="lg:hidden" x-data="{ panelOpen: false }">
        <button type="button" @click="panelOpen = ! panelOpen"
                class="pointer-events-auto fixed right-0 top-1/2 z-[510] flex -translate-y-1/2 flex-col items-center gap-1.5 rounded-l-lg bg-radar-bg px-1.5 py-3 text-slate-200 shadow-lg shadow-black/40"
                :aria-expanded="panelOpen"
                aria-label="{{ __('切換圖層') }}">
            <span class="text-lg leading-none" x-text="panelOpen ? '›' : '‹'"></span>
            <span class="text-xs tracking-[0.15em] [writing-mode:vertical-rl]" x-show="! panelOpen">{{ __('切換圖層') }}</span>
        </button>
        <div x-show="panelOpen" x-cloak x-transition.origin.right
             class="pointer-events-auto fixed bottom-24 right-0 top-16 z-[505] flex w-64 flex-col gap-3 overflow-y-auto rounded-l-xl border-l border-white/10 bg-radar-bg/98 p-3 backdrop-blur">
            <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('圖層') }}</p>
            <div class="flex flex-col gap-1">
                @include('partials.map-layer-chips')
            </div>
        </div>
    </div>

    {{-- 登入引導 / 回報表單 (Livewire Modal) --}}
    <livewire:auth-onboarding />
    <livewire:report-form />
    <livewire:gacha-player />
    <livewire:contact-form />
</div>

@include('partials.gacha-stage')

@if (session('open_report_form'))
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.dispatch('open-report-form');
        });
    </script>
@endif
@endsection
