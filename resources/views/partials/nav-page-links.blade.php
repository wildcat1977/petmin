{{--
    共用選單連結（v0.3 二次回饋 item 6）：最新消息/通報清單/透明度報告/統計地圖。
    單一來源，供 partials/nav.blade.php（次要頁面）與首頁頂帶（radar.blade.php）共用，
    避免兩處各自維護一份、首頁漏掉次要頁面連結。
--}}
@php
    $navPageLinkCls = fn (string $route): string => Route::is($route)
        ? 'text-xs text-slate-200 font-medium'
        : 'text-xs text-slate-400 hover:text-slate-200 transition-colors';
@endphp

<a href="{{ route('news') }}" class="{{ $navPageLinkCls('news') }}">{{ __('最新消息') }}</a>
<a href="{{ route('reports') }}" class="{{ $navPageLinkCls('reports') }}">{{ __('通報清單') }}</a>
<a href="{{ route('transparency') }}" class="{{ $navPageLinkCls('transparency') }}">{{ __('透明度報告') }}</a>
<a href="{{ route('stats') }}" class="{{ $navPageLinkCls('stats') }}">{{ __('統計地圖') }}</a>
