{{--
    全站共用 head nav：logo + 跑馬燈 + 選單，首頁（浮在地圖上）與
    次要頁面（profile/reports/news...一般文件流）共用同一份，不再各自維護一份 header。
    首頁外層包在 pointer-events-none 浮層裡，這裡的 pointer-events-auto 才讓按鈕可點；
    次要頁面沒有那層外殼，pointer-events-auto 在一般文件流裡是無害的預設值。
--}}
<header class="pointer-events-auto bg-radar-bg shadow-lg shadow-black/30"
        x-data="{ mobileNavOpen: false }" @keydown.escape.window="mobileNavOpen = false">
    <div class="flex items-center gap-3 px-4 py-2">
        <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2">
            <span class="text-2xl leading-none">🐾</span>
            <span class="text-lg font-black tracking-tight text-amber-400">Petmin</span>
            <span class="hidden text-[11px] text-slate-400 sm:inline">{{ __('找回消失的垃圾桶') }}</span>
        </a>

        {{-- 跑馬燈廣播：獨立 site-ticker 元件供應資料（不必掛整個地圖），
             訊息過寬時 app.js 的 initTicker() 會加 .radar-ticker-marquee 向左捲動播完整句，
             而非用 truncate 把後半段文字直接裁掉（v0.3 三次回饋）。--}}
        <livewire:site-ticker />
        <div class="relative min-w-0 flex-1 overflow-hidden rounded-full bg-black/30 px-4 py-2">
            <p id="radar-ticker-text"
               class="radar-ticker text-center text-[13px] font-medium text-slate-100">
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
                {{-- 直連 Google 會跳過 LINE/Google 選擇與個資告知聲明（spec §12.16）；
                     改成跟「立即回報」guest 流程一致，開啟登入引導 modal（PM 回饋）。 --}}
                <button type="button" onclick="window.Livewire.dispatch('open-auth-onboarding')"
                        class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">{{ __('登入') }}</button>
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
            <a href="{{ route('candidates') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('候選復設點') }}</a>
            <a href="{{ route('reports') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('回報清單') }}</a>
            <a href="{{ route('leaderboard') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('皮克敏榜') }}</a>
            <a href="{{ route('news') }}" @click="mobileNavOpen = false" class="rounded-lg px-3 py-2 text-slate-300 hover:bg-white/10">{{ __('最新消息') }}</a>
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
                <button type="button"
                        @click="mobileNavOpen = false; window.Livewire.dispatch('open-auth-onboarding')"
                        class="w-full rounded-lg px-3 py-2 text-left text-slate-300 hover:bg-white/10">{{ __('登入') }}</button>
            @endauth
        </nav>
    </div>
</header>
