<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $open = false;

    public string $browserType = 'normal'; // 'normal' | 'line' | 'other_inapp'

    public function mount(): void
    {
        $ua = request()->userAgent() ?? '';
        if (str_contains($ua, 'Line/')) {
            $this->browserType = 'line';
        } elseif (preg_match('/FBAN|FBAV|Instagram|MicroMessenger|Threads|Twitter|TikTok|Snapchat/i', $ua)) {
            $this->browserType = 'other_inapp';
        }
    }

    #[On('open-auth-onboarding')]
    public function show(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }
}; ?>

<div>
    @if ($open)
        <div x-data="{ showPolicy: false }">
        <div class="fixed inset-0 z-[1000] flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center"
             wire:click.self="close">
            <div class="w-full max-w-md rounded-t-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10 sm:rounded-3xl">
                <h2 class="text-xl font-bold">{{ __('加入 Petmin · 遛狗順手回報') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('為了回報精準度，我們需要您協助：') }}</p>

                <ol class="mt-4 space-y-3 text-sm">
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">1</span>
                        <span>{!! __('看到垃圾桶滿了、消失了、或這裡該有一個，拿出手機<strong>拍張照片</strong>。') !!}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">2</span>
                        <span>{!! __('允許瀏覽器存取<strong>地理位置</strong>，標記發現點。') !!}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">3</span>
                        <span>{{ __('送出回報，地圖即時更新；核准後加點，累積上皮克敏榜。') }}</span>
                    </li>
                </ol>

                <p class="mt-4 text-[11px] leading-relaxed text-slate-500">
                    {{ __('登入即代表您同意我們儲存上傳之照片與當下的地理位置資訊，僅用於本平台之垃圾桶回報用途。') }}
                </p>
                <button type="button" @click="showPolicy = true"
                        class="mt-2 text-left text-[11px] font-medium text-cyan-400 underline decoration-cyan-400/40 underline-offset-2 transition hover:text-cyan-300">
                    {{ __('詳閱完整《個人資料蒐集、處理及利用告知聲明》') }}
                </button>

                <div class="mt-6 space-y-3">
                    @if ($browserType === 'other_inapp')
                        {{-- 非 LINE 的 in-app 瀏覽器（FB/IG/Threads 等）：Google 會 403，無法登入 --}}
                        <div class="rounded-xl bg-amber-500/15 px-4 py-3 text-sm text-amber-300 ring-1 ring-amber-500/30">
                            <p class="font-semibold">{!! __('⚠️ 目前在 App 內建瀏覽器') !!}</p>
                            <p class="mt-1 text-amber-400/80">{!! __('Google 登入在此環境會被封鎖（error 403）。請複製網址，改用 <strong>Safari</strong> 或 <strong>Chrome</strong> 開啟後再登入。') !!}</p>
                        </div>
                        <button
                            onclick="navigator.clipboard?.writeText(window.location.href).then(() => { this.textContent = '{{ __('✓ 已複製！') }}'; setTimeout(() => this.textContent = '{{ __('複製網址') }}', 2000); })"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-slate-700 px-4 py-3 font-semibold text-slate-200 transition hover:bg-slate-600">
                            {{ __('複製網址') }}
                        </button>
                        {{-- LINE 登入仍然可用（若使用者有 LINE 帳號） --}}
                        <a href="{{ route('auth.redirect', 'line') }}"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#06C755] px-4 py-3 font-semibold text-white transition hover:bg-[#05b34c]">
                            <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.070 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                            {{ __('或用 LINE 帳號登入') }}
                        </a>
                    @elseif ($browserType === 'line')
                        {{-- LINE 內建瀏覽器：LIFF 自動處理，fallback 顯示 LINE 登入按鈕 --}}
                        <a href="{{ route('auth.redirect', 'line') }}"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#06C755] px-4 py-3 font-semibold text-white transition hover:bg-[#05b34c]">
                            <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.070 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                            {{ __('使用 LINE 登入') }}
                        </a>
                    @else
                        {{-- 一般外部瀏覽器：LINE + Google 都可用 --}}
                        <a href="{{ route('auth.redirect', 'line') }}"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#06C755] px-4 py-3 font-semibold text-white transition hover:bg-[#05b34c]">
                            <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.070 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                            {{ __('使用 LINE 登入') }}
                        </a>
                        <a href="{{ route('auth.redirect', 'google') }}"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-white px-4 py-3 font-semibold text-slate-900 transition hover:bg-slate-100">
                            {{ __('使用 Google 登入') }}
                        </a>
                    @endif
                </div>

                <button type="button" wire:click="close"
                        class="mt-4 block w-full text-center text-xs text-slate-500 hover:text-slate-300">
                    {{ __('稍後再說') }}
                </button>
            </div>
        </div>

        {{-- 個資法告知聲明（spec §12.16）：Alpine 本地開關，疊於登入 modal 之上；靜態內容不走 Livewire 往返 --}}
        <div x-show="showPolicy" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="fixed inset-0 z-[1100] flex items-end justify-center bg-black/80 backdrop-blur-sm sm:items-center sm:p-4"
             @click.self="showPolicy = false">
            <div class="flex max-h-[88dvh] w-full max-w-lg flex-col rounded-t-3xl bg-[#161b22] text-slate-100 shadow-2xl ring-1 ring-white/10 sm:max-h-[85dvh] sm:rounded-3xl">
                <div class="flex items-start justify-between gap-4 border-b border-white/10 px-6 pb-4 pt-6">
                    <h2 class="text-lg font-bold leading-snug">{{ __('個人資料蒐集、處理及利用告知聲明') }}</h2>
                    <button type="button" @click="showPolicy = false"
                            class="-mr-1 shrink-0 rounded-full p-1 text-slate-400 transition hover:bg-white/10 hover:text-slate-200"
                            aria-label="{{ __('關閉') }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="grow space-y-4 overflow-y-auto px-6 py-5 text-[13px] leading-relaxed text-slate-300">
                    <p>為保障網路通訊安全並防堵惡意程式（機器人），Petmin 垃圾桶回報（以下簡稱本網站）依《個人資料保護法》規定告知下列事項。當您使用 LINE 帳號或 Google 帳號登入時，即表示同意本聲明：</p>

                    <div>
                        <p class="font-semibold text-slate-100">一、本網站蒐集您個人資料的目的在於：</p>
                        <ol class="mt-1 list-decimal space-y-1 pl-6">
                            <li>資訊安全與防護：防堵自動化程式（機器人）、惡意攻擊、詐欺、破壞性行為或任何違反本網站服務條款之惡意行為，以維護系統穩定與安全。</li>
                            <li>使用者身分驗證：透過第三方認證機制確認使用者真實身分，降低匿名惡意註冊之風險。</li>
                            <li>客戶管理與服務：進行資訊管理、提供相關服務、統計分析或排除技術障礙。</li>
                        </ol>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-100">二、資料類別：</p>
                        <ol class="mt-1 list-decimal space-y-1 pl-6">
                            <li>第三方平台授權之基本資料。</li>
                            <li>網路技術紀錄：IP 位址、連線時間、操作行為與裝置資訊，用於異常流量偵測。</li>
                        </ol>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-100">三、利用期間、地區、對象及方式：</p>
                        <p class="mt-1">於本網站營運期間與地區內，由本網站以電子化方式進行身分驗證與資安行為分析。除非法律另有規定、或為配合司法機關調查外，本網站不會將您的資料提供給任何無關之第三方。</p>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-100">四、您的權利：</p>
                        <p class="mt-1">依據個資法第三條規定，您就本網站保有之個人資料，得行使以下權利：</p>
                        <ol class="mt-1 list-decimal space-y-1 pl-6">
                            <li>查詢、請求閱覽或請求製給複製本。</li>
                            <li>請求補充或更正。</li>
                            <li>請求停止蒐集、處理或利用，或請求刪除。</li>
                        </ol>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-100">五、不提供之影響：</p>
                        <p class="mt-1">您可自由選擇是否提供，但若不同意，本網站將無法進行安全驗證，您將無法使用回報功能。</p>
                    </div>
                </div>

                <div class="border-t border-white/10 px-6 py-4">
                    <button type="button" @click="showPolicy = false"
                            class="w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold text-white transition hover:bg-cyan-500">
                        {{ __('我知道了') }}
                    </button>
                </div>
            </div>
        </div>
        </div>
    @endif
</div>
