@extends('layouts.app')

@section('title', __('巡桶打卡 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    <main class="mx-auto max-w-xl px-4 py-6">
        {{-- 碰 NFC 貼紙或掃 QR 進來的第一個畫面：先給打卡成就感，再引導回報 --}}
        {{-- 印章圖的緞帶是空白的（圖高 75–84%），字用網頁疊上去，改字不用重畫 --}}
        <div class="checkin-stamp">
            <img src="/gacha/stamp.webp" alt="" width="520" height="465">
            <span class="checkin-stamp-text">{{ __('打卡成功') }}</span>
        </div>

        <div class="mt-6 rounded-2xl bg-radar-card p-5 shadow">
            <p class="text-xs text-slate-400">{{ $kindLabel }}{{ $bin->district ? ' · '.$bin->district : '' }}</p>
            <h1 class="mt-1 text-xl font-bold">🗑️ {{ $bin->address ?: __('清潔箱') }}</h1>
            <p class="mt-2 text-sm text-slate-300">
                @if ($bin->last_verified_at)
                    {{ __(':days 天前有人確認還在', ['days' => (int) $bin->last_verified_at->diffInDays(now())]) }}
                @else
                    {{ __('你是第一個來巡這個桶的人！') }}
                @endif
            </p>
        </div>

        <button type="button" data-checkin-report
                class="mt-5 w-full rounded-xl bg-amber-400 px-4 py-4 text-lg font-extrabold text-amber-950 shadow-lg hover:bg-amber-300">
            📷 {{ __('回報這個桶的狀況') }}
        </button>
        <p class="mt-2 text-center text-xs text-slate-400">{{ __('拍一張照片，選「垃圾桶壞了」「臭臭的」「垃圾桶滿了！」或「桶還在」') }}</p>

        <a href="{{ route('find') }}" class="mt-6 block text-center text-sm text-sky-300 underline underline-offset-4">
            {{ __('不是這個桶？找附近其他桶') }}
        </a>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>

    <livewire:report-form />
    <livewire:gacha-player />
</div>

@include('partials.gacha-stage')

<style>
    .checkin-stamp { position: relative; width: min(64%, 260px); margin: 0 auto; animation: checkin-stamp .7s cubic-bezier(.3, 1.6, .5, 1) both; }
    .checkin-stamp img { display: block; width: 100%; height: auto; filter: drop-shadow(0 8px 14px rgba(0, 0, 0, .35)); }
    .checkin-stamp-text { position: absolute; left: 0; right: 0; top: 79.5%; transform: translateY(-50%); text-align: center; font-size: clamp(18px, 6vw, 26px); font-weight: 900; letter-spacing: .12em; color: #fff7d6; text-shadow: 0 2px 0 #8a1c12, 0 0 6px rgba(0, 0, 0, .35); }
    @keyframes checkin-stamp {
        0% { transform: scale(2.4) rotate(-20deg); opacity: 0; }
        70% { transform: scale(.94) rotate(-4deg); opacity: 1; }
        100% { transform: scale(1) rotate(-6deg); }
    }
    @media (prefers-reduced-motion: reduce) { .checkin-stamp { animation: none; } }
</style>
<script>
    document.querySelector('[data-checkin-report]').addEventListener('click', () => {
        window.Livewire.dispatch('open-report-form', { binId: {{ $bin->id }}, requirePhoto: true });
    });
</script>
@endsection
