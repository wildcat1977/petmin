<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Petmin 找回消失的垃圾桶 | 台北市遛狗族垃圾桶地圖')</title>

    {{-- SEO / OG --}}
    <meta name="description" content="遛狗順手回報：垃圾桶滿了、消失了、這裡需要一個。把市民需求變成台北市垃圾桶復設的決策資料。">
    <meta name="theme-color" content="{{ config('petmin.theme.bg') }}">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:title" content="Petmin 找回消失的垃圾桶">
    <meta property="og:description" content="遛狗順手回報：垃圾桶滿了、消失了、這裡需要一個。把市民需求變成台北市垃圾桶復設的決策資料。">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="Petmin">
    <meta property="og:locale" content="zh_TW">
    <meta property="og:image" content="{{ asset('social-image.png') }}">
    <meta property="og:image:width" content="1731">
    <meta property="og:image:height" content="909">
    <meta property="og:image:alt" content="Petmin 找回消失的垃圾桶">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('social-image.png') }}">

    @include('partials.gtag')

    {{-- Leaflet CSS --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

    {{-- i18n for JavaScript --}}
    <script>
        window.i18n = {
            vlUnverified:           @js(__('未核實')),
            vlCorroborated:         @js(__('多方佐證')),
            vlOfficial:             @js(__('官方核實')),
            stNotified:             @js(__('📣 已通報')),
            stResolved:             @js(__('✅ 已處理')),
            binCleanBox:            @js(__('行人專用清潔箱')),
            binBagBox:              @js(__('狗便清潔袋箱')),
            binActive:              @js(__('使用中')),
            binMissing:             @js(__('消失／已撤')),
            binUnverified:          @js(__('待驗證')),
            binVerified:            @js(__('最近驗證')),
            binNeverVerified:       @js(__('尚未有人驗證')),
            binRemovedFromList:     @js(__('環保局清單移除於')),
            binReports:             @js(__('筆回報')),
            binReportThis:          @js(__('🐾 回報這個桶')),
            gapBoxes:               @js(__('狗便袋箱')),
            gapNearest:             @js(__('最近清潔箱')),
            gapNone:                @js(__('300m 內沒有')),
            demandScore:            @js(__('需求分數')),
            demandEmpty:            @js(__('這一格目前沒有需求訊號')),
            demandHint:             @js(__('🔥 點紅色區塊，看為什麼需要桶')),
            demandHintClose:        @js(__('不再提示')),
            demandLoading:          @js(__('需求熱力載入中…')),
            reportPhoto:            @js(__('回報照片')),
            searchPlaceholderRadar: @js(__('搜尋地標或路名…(尚不支援詳細地址)')),
            searchPlaceholderPin:   @js(__('搜尋地標或路名…(暫不支援門牌號碼)')),
            myLocation:             @js(__('📍 我的位置')),
            getGPS:                 @js(__('取得目前 GPS 位置')),
            locating:               @js(__('定位中…')),
        };
    </script>
    {{-- 自架底圖設定（docs/basemap.md）：所有前台地圖經 window.__PETMIN_BASEMAP__.create('light'|'dark') 取用 --}}
    <script>
        window.__PETMIN_BASEMAP_CONFIG__ = @js([
            'styles' => config('petmin.map.basemap_styles'),
            'attribution' => config('petmin.map.basemap_attribution'),
            'maxZoom' => (int) config('petmin.map.max_zoom'),
        ]);
    </script>
    {{-- 頁面自己的預載（主地圖的底圖 style）：要排在 app.js 之前才能跟它同時下載 --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- 主題配色覆蓋：以 config('petmin.theme') 覆蓋 app.css @theme
         的 --color-radar-bg / --color-radar-card 預設，改 env 即可全站換色不必重編樣式。
         需放在 @vite 之後才蓋得過 Tailwind 產出的 :root 變數。 --}}
    <style>
        :root {
            --color-radar-bg: {{ config('petmin.theme.bg') }};
            --color-radar-card: {{ config('petmin.theme.card') }};
        }
    </style>

    {{-- LIFF SDK（僅在設定了 LIFF_ID 時載入） --}}
    @if(config('services.line.liff_id'))
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script>
        window.__LIFF_ID__ = @js(config('services.line.liff_id'));
        window.__AUTHED__  = @js(auth()->check());
    </script>
    @endif
</head>
<body class="bg-radar-bg text-slate-100 antialiased min-h-dvh">
    {{ $slot ?? '' }}
    @yield('content')

    @include('partials.disclaimer-modal')

    @livewireScripts
</body>
</html>
