<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hazdar 全民韌性災害回報系統 | 台北市災情地圖')</title>

    {{-- SEO / OG --}}
    <meta name="description" content="颱風豪雨災時，回報淹水、道路中斷、停電、結構受損，即時匯聚成台北市單一災情情勢圖。">
    <meta name="theme-color" content="{{ config('hazdar.theme.bg') }}">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:title" content="Hazdar 全民韌性災害回報系統">
    <meta property="og:description" content="颱風豪雨災時，回報淹水、道路中斷、停電、結構受損，即時匯聚成台北市單一災情情勢圖。">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">

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
            stResolved:             @js(__('✅ 已排除')),
            shelterOpen:            @js(__('🟢 開設中')),
            shelterClosed:          @js(__('⚪ 未開設')),
            shelterCapacity:        @js(__('容量')),
            reportPhoto:            @js(__('回報照片')),
            searchPlaceholderRadar: @js(__('搜尋地標或路名…(尚不支援詳細地址)')),
            searchPlaceholderPin:   @js(__('搜尋地標或路名…(暫不支援門牌號碼)')),
            myLocation:             @js(__('📍 我的位置')),
            getGPS:                 @js(__('取得目前 GPS 位置')),
            locating:               @js(__('定位中…')),
        };
    </script>
    {{-- 自架底圖設定（docs/basemap.md）：所有前台地圖經 window.__HAZDAR_BASEMAP__.create('light'|'dark') 取用 --}}
    <script>
        window.__HAZDAR_BASEMAP_CONFIG__ = @js([
            'styles' => config('hazdar.map.basemap_styles'),
            'attribution' => config('hazdar.map.basemap_attribution'),
            'maxZoom' => (int) config('hazdar.map.max_zoom'),
        ]);
    </script>
    {{-- 頁面自己的預載（主地圖的底圖 style）：要排在 app.js 之前才能跟它同時下載 --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- 主題配色覆蓋（v0.3 五次回饋 item 4）：以 config('hazdar.theme') 覆蓋 app.css @theme
         的 --color-radar-bg / --color-radar-card 預設，改 env 即可全站換色不必重編樣式。
         需放在 @vite 之後才蓋得過 Tailwind 產出的 :root 變數。 --}}
    <style>
        :root {
            --color-radar-bg: {{ config('hazdar.theme.bg') }};
            --color-radar-card: {{ config('hazdar.theme.card') }};
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
