@extends('layouts.app')

@section('title', __('佔領垃圾桶 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    <main class="mx-auto max-w-xl px-4 py-6">
        {{-- 碰 NFC 貼紙或掃 QR 進來的第一個畫面：先給佔領成就感，再引導回報 --}}
        {{-- 印章圖的緞帶是空白的（圖高 75–84%），字用網頁疊上去，改字不用重畫 --}}
        <div class="checkin-stamp">
            <img src="/gacha/stamp.webp" alt="" width="520" height="465">
            <span class="checkin-stamp-text">{{ __('佔領成功') }}</span>
        </div>

        <div class="mt-6 rounded-2xl bg-radar-card p-5 shadow">
            <p class="text-xs text-slate-400">{{ __($kindLabel) }}{{ $bin->district ? ' · '.$bin->district : '' }}</p>
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

        <livewire:bin-occupation :bin-id="$bin->id" />

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

{{-- 碰貼紙／掃碼進來的開場短動畫：「今日巡桶任務開始」，同一桶一天播一次，點一下可略過 --}}
@if (in_array(request('via'), ['nfc', 'qr', 'line'], true))
    <div data-mission-intro class="mission-intro" hidden>
        <div class="mission-stage">
            <img src="/gacha/dog-happy.webp" alt="" class="mission-dog">
            <div class="mission-bubble">{{ __('汪！今日巡桶任務開始！') }}</div>
            <div class="mission-caption">🗑️ {{ $bin->address ?: __('清潔箱') }}</div>
        </div>
        <p class="mission-skip">{{ __('點一下略過') }}</p>
    </div>
@endif

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
    .mission-intro { position: fixed; inset: 0; z-index: 1900; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 16px; background: rgba(4, 14, 10, .85); }
    .mission-intro[hidden] { display: none; }
    .mission-stage { position: relative; width: min(100%, 380px); aspect-ratio: 2 / 3; border-radius: 22px; overflow: hidden; background: url('/gacha/bg-street.webp') center / cover; box-shadow: 0 10px 30px rgba(0, 0, 0, .45); }
    .mission-dog { position: absolute; width: 46%; left: 27%; bottom: 10%; filter: drop-shadow(0 8px 8px rgba(0, 0, 0, .3)); animation: mission-run .9s cubic-bezier(.3, 1.4, .5, 1) both, mission-hop .45s ease-in-out .9s infinite; }
    @keyframes mission-run { from { transform: translateX(-160%) rotate(-12deg); } to { transform: translateX(0) rotate(0); } }
    @keyframes mission-hop { 0%, 100% { translate: 0 0; } 50% { translate: 0 -14px; } }
    .mission-bubble { position: absolute; left: 8%; right: 8%; top: 10%; background: #fff; color: #2b2320; border: 3px solid #2b2320; border-radius: 18px; padding: 12px 14px; text-align: center; font-size: 20px; font-weight: 900; box-shadow: 0 4px 0 rgba(0, 0, 0, .25); animation: mission-pop .4s cubic-bezier(.3, 1.6, .5, 1) .8s both; }
    @keyframes mission-pop { from { transform: scale(.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .mission-caption { position: absolute; left: 10px; right: 10px; bottom: 10px; background: rgba(20, 16, 14, .78); color: #fff; font-size: 15px; font-weight: 700; padding: 8px 12px; border-radius: 12px; text-align: center; }
    .mission-skip { color: rgba(255, 255, 255, .6); font-size: 13px; }
    @media (prefers-reduced-motion: reduce) { .mission-dog, .mission-bubble { animation: none; } }
</style>
<script>
    (() => {
        const intro = document.querySelector('[data-mission-intro]');
        if (!intro) return;
        // 同一桶同一天只播一次；storage 不能用時照播
        const key = 'petmin:mission-intro:{{ $bin->id }}:' + new Date().toISOString().slice(0, 10);
        try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) {}
        intro.hidden = false;
        const close = () => { intro.hidden = true; };
        intro.addEventListener('click', close);
        setTimeout(close, 3200);
    })();
</script>
<script>
    document.querySelector('[data-checkin-report]').addEventListener('click', () => {
        window.Livewire.dispatch('open-report-form', { binId: {{ $bin->id }}, requirePhoto: true });
    });
</script>
@endsection
