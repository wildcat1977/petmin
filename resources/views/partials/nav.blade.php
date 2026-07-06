@php
    $navCls = fn (string $route): string => Route::is($route)
        ? 'text-sm text-slate-200 font-medium'
        : 'text-sm text-slate-400 hover:text-slate-200 transition-colors';
    $navClsXs = fn (string $route): string => Route::is($route)
        ? 'text-xs text-slate-200 font-medium'
        : 'text-xs text-slate-400 hover:text-slate-200 transition-colors';
@endphp

{{-- ── 頂部導覽列 ── --}}
<div x-data="{ open: false }" @keydown.escape.window="open = false">

    <header class="flex items-center justify-between border-b border-white/10 px-4 py-3">
        <a href="{{ route('home') }}" class="flex items-center gap-2 text-sm font-semibold text-slate-200">
            <span class="text-sky-500">◎</span> Hazdar
        </a>

        <div class="flex items-center gap-3">
            {{-- 桌機連結（md 以上顯示）--}}
            <div class="hidden md:flex items-center gap-3">
                @include('partials.locale-switcher')

                <a href="{{ route('reports') }}" class="{{ $navClsXs('reports') }}">{{ __('通報清單') }}</a>
                <a href="{{ route('transparency') }}" class="{{ $navClsXs('transparency') }}">{{ __('透明度報告') }}</a>
                <a href="{{ route('stats') }}" class="{{ $navClsXs('stats') }}">{{ __('統計地圖') }}</a>
                <button type="button"
                        onclick="window.dispatchEvent(new CustomEvent('open-contact-form'))"
                        class="text-xs text-slate-400 hover:text-slate-200 transition-colors">{{ __('聯絡管理員') }}</button>

                @auth
                    <a href="{{ route('profile') }}" class="{{ $navClsXs('profile') }}">{{ __('我的回報') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full bg-white/10 px-3 py-1.5 text-xs text-slate-300 hover:bg-white/20 transition-colors">{{ __('登出') }}</button>
                    </form>
                @else
                    <a href="{{ route('auth.redirect', ['provider' => 'google']) }}"
                       class="rounded-full bg-white/10 px-3 py-1.5 text-xs text-slate-300 hover:bg-white/20 transition-colors">{{ __('登入') }}</a>
                @endauth
            </div>

            {{-- 手機：語系切換器 + 漢堡按鈕 --}}
            <div class="flex items-center gap-2 md:hidden">
                @include('partials.locale-switcher')
                <button type="button" @click="open = !open"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-slate-200 transition-colors"
                        :aria-expanded="open" aria-label="{{ __('選單') }}">
                    <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    <svg x-show="open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    {{-- 手機展開選單 --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-1"
         class="border-b border-white/10 bg-[#0d1117] px-4 py-3 md:hidden">

        <nav class="flex flex-col gap-1">
            <a href="{{ route('reports') }}"
               class="rounded-lg px-3 py-2 {{ $navCls('reports') }}"
               @click="open = false">{{ __('通報清單') }}</a>

            <a href="{{ route('transparency') }}"
               class="rounded-lg px-3 py-2 {{ $navCls('transparency') }}"
               @click="open = false">{{ __('透明度報告') }}</a>

            <a href="{{ route('stats') }}"
               class="rounded-lg px-3 py-2 {{ $navCls('stats') }}"
               @click="open = false">{{ __('統計地圖') }}</a>

            <button type="button"
                    onclick="open = false; window.dispatchEvent(new CustomEvent('open-contact-form'))"
                    class="rounded-lg px-3 py-2 text-left text-sm text-slate-400 hover:text-slate-200 transition-colors">{{ __('聯絡管理員') }}</button>

            <div class="my-1 border-t border-white/10"></div>

            @auth
                <a href="{{ route('profile') }}"
                   class="rounded-lg px-3 py-2 {{ $navCls('profile') }}"
                   @click="open = false">{{ __('我的回報') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-400 hover:text-slate-200 transition-colors">{{ __('登出') }}</button>
                </form>
            @else
                <a href="{{ route('auth.redirect', ['provider' => 'google']) }}"
                   class="rounded-lg px-3 py-2 text-sm text-slate-400 hover:text-slate-200 transition-colors">{{ __('登入') }}</a>
            @endauth
        </nav>
    </div>

</div>

{{-- 聯絡表單 Modal（由 open-contact-form 事件觸發）--}}
<livewire:contact-form />
