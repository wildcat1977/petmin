{{-- 共用選單連結：單一來源，供次要頁面 nav 與首頁頂帶共用 --}}
@php
    $navPageLinkCls = fn (string $route): string => Route::is($route)
        ? 'text-xs text-slate-200 font-medium'
        : 'text-xs text-slate-400 hover:text-slate-200 transition-colors';
@endphp

<a href="{{ route('about') }}" class="{{ $navPageLinkCls('about') }}">{{ __('關於 Petmin') }}</a>
<a href="{{ route('candidates') }}" class="{{ $navPageLinkCls('candidates') }}">{{ __('數據判讀') }}</a>
<a href="{{ route('fill-speed') }}" class="{{ $navPageLinkCls('fill-speed') }}">{{ __('多快會滿') }}</a>
<a href="{{ route('reports') }}" class="{{ $navPageLinkCls('reports') }}">{{ __('回報清單') }}</a>
<a href="{{ route('leaderboard') }}" class="{{ $navPageLinkCls('leaderboard') }}">{{ __('巡桶英雄榜') }}</a>
<a href="{{ route('news') }}" class="{{ $navPageLinkCls('news') }}">{{ __('最新消息') }}</a>
