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
        <div class="fixed inset-0 z-[1000] flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center"
             wire:click.self="close">
            <div class="w-full max-w-md rounded-t-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10 sm:rounded-3xl">
                <h2 class="text-xl font-bold">{{ __('加入 CivicShield · 一起回報災情') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('為了回報精準度，我們需要您協助：') }}</p>

                <ol class="mt-4 space-y-3 text-sm">
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">1</span>
                        <span>{!! __('發現淹水、道路中斷等災情時，拿出手機<strong>拍張照片</strong>。') !!}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">2</span>
                        <span>{!! __('允許瀏覽器存取<strong>地理位置</strong>，標記發現點。') !!}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600/20 text-red-400">3</span>
                        <span>{{ __('送出回報，災情地圖即時更新。') }}</span>
                    </li>
                </ol>

                <p class="mt-4 text-[11px] leading-relaxed text-slate-500">
                    {{ __('登入即代表您同意我們儲存上傳之照片與當下的地理位置資訊，僅用於本平台之災情回報用途。') }}
                </p>

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
    @endif
</div>
