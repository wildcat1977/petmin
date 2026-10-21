@extends('layouts.app')

@section('title', __('巡桶打卡 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    <main class="mx-auto max-w-xl px-4 py-6">
        {{-- 碰 NFC 貼紙或掃 QR 進來的第一個畫面：先給打卡成就感，再引導回報 --}}
        <div class="checkin-stamp mx-auto flex h-36 w-36 flex-col items-center justify-center rounded-full border-4 border-emerald-400 bg-emerald-500/15 text-center">
            <span class="text-4xl">🐾</span>
            <span class="mt-1 text-lg font-extrabold text-emerald-300">{{ __('打卡成功') }}</span>
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
        <p class="mt-2 text-center text-xs text-slate-400">{{ __('拍一張照片，選「滿了」「壞了」「有異味」或「正常」') }}</p>

        <a href="{{ route('find') }}" class="mt-6 block text-center text-sm text-sky-300 underline underline-offset-4">
            {{ __('不是這個桶？找附近其他桶') }}
        </a>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>

    <livewire:report-form />
</div>

<style>
    .checkin-stamp { animation: checkin-stamp .6s cubic-bezier(.3, 1.6, .5, 1) both; }
    @keyframes checkin-stamp {
        from { transform: scale(2.2) rotate(-18deg); opacity: 0; }
        to { transform: scale(1) rotate(-6deg); opacity: 1; }
    }
</style>
<script>
    document.querySelector('[data-checkin-report]').addEventListener('click', () => {
        window.Livewire.dispatch('open-report-form', { binId: {{ $bin->id }} });
    });
</script>
@endsection
