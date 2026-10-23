@extends('layouts.app')

@section('title', __('關於 Petmin'))

@push('head')
    {{-- 只有這一頁用 QR 產生器，從 CDN 載入、不進 npm 依賴（同 /print/bin-stickers） --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
@endpush

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    {{-- 給第一次接觸的人（評審、民眾）：網頁看資料、LINE 參與回報，兩邊分開講 --}}
    <main class="mx-auto max-w-3xl px-4 py-8">
        <section class="text-center">
            <p class="text-sm font-bold tracking-widest text-amber-300">PETMIN 🐾</p>
            <h1 class="mt-3 text-3xl font-extrabold leading-tight sm:text-4xl">{{ __('找回消失的垃圾桶') }}</h1>
            <p class="mx-auto mt-3 max-w-xl text-slate-300">{{ __('台北街上的垃圾桶越來越少，該放回哪裡、哪些桶太快滿？Petmin 把市民的回報變成公開資料，讓城市知道垃圾桶該怎麼擺。') }}</p>
        </section>

        <section class="mt-10 grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl border border-emerald-400/40 bg-radar-card p-5">
                <p class="text-xs font-bold tracking-wider text-emerald-300">{{ __('網頁・給所有人') }}</p>
                <h2 class="mt-1 text-xl font-bold">{{ __('看哪裡需要垃圾桶') }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-300">
                    <li>{{ __('垃圾桶地圖與需求熱力') }}</li>
                    <li>{{ __('依需求排出的候選設桶點') }}</li>
                    <li>{{ __('垃圾桶多快會滿') }}</li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('candidates') }}" class="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-bold text-emerald-950 hover:bg-emerald-400">{{ __('看數據判讀') }}</a>
                    <a href="{{ route('home') }}" class="rounded-lg border border-slate-500 px-3 py-2 text-sm text-slate-200 hover:border-slate-300">{{ __('打開地圖') }}</a>
                </div>
            </div>
            <div class="rounded-2xl border border-amber-400/50 bg-radar-card p-5">
                <p class="text-xs font-bold tracking-wider text-amber-300">{{ __('LINE・給遛狗的你') }}</p>
                <h2 class="mt-1 text-xl font-bold">{{ __('散步順手巡垃圾桶') }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-300">
                    <li>{{ __('我找垃圾桶：最近的桶在哪、走過去多遠') }}</li>
                    <li>{{ __('回報垃圾桶狀況：滿了、壞了、臭臭的') }}</li>
                    <li>{{ __('太遠就按「好遠！」，告訴大家這裡需要桶') }}</li>
                </ul>
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-center text-xl font-bold">{{ __('好玩的地方') }}</h2>
            <div class="mt-4 grid grid-cols-3 gap-3 text-center text-sm">
                <div class="rounded-2xl bg-radar-card p-3">
                    <img src="/gacha/stamp.webp" alt="" class="mx-auto h-20 w-auto">
                    <p class="mt-2 font-bold">{{ __('佔領垃圾桶') }}</p>
                    <p class="text-xs text-slate-400">{{ __('跳出今日任務動畫') }}</p>
                </div>
                <div class="rounded-2xl bg-radar-card p-3">
                    <img src="/gacha/dog-rich.webp" alt="" class="mx-auto h-20 w-auto">
                    <p class="mt-2 font-bold">{{ __('回報抽扭蛋') }}</p>
                    <p class="text-xs text-slate-400">{{ __('隨機一段好笑劇情') }}</p>
                </div>
                <div class="rounded-2xl bg-radar-card p-3">
                    <img src="/gacha/card-clean.webp" alt="" class="mx-auto h-20 w-auto">
                    <p class="mt-2 font-bold">{{ __('收集垃圾桶圖鑑') }}</p>
                    <p class="text-xs text-slate-400">{{ __('分享給朋友一起來') }}</p>
                </div>
            </div>
        </section>

        <section class="mt-10 rounded-3xl bg-amber-400 p-6 text-amber-950 sm:flex sm:items-center sm:gap-6">
            <div class="mx-auto w-40 shrink-0 rounded-2xl bg-white p-3 sm:mx-0" data-line-qr data-url="{{ config('petmin.line_oa.add_friend_url') }}"></div>
            <div class="mt-4 text-center sm:mt-0 sm:text-left">
                <h2 class="text-2xl font-extrabold">{{ __('加入 LINE，帶狗狗出任務') }}</h2>
                <p class="mt-1 text-sm">{{ __('用手機掃 QR Code，或搜尋 LINE ID') }} <strong>{{ config('petmin.line_oa.id') }}</strong></p>
                <a href="{{ config('petmin.line_oa.add_friend_url') }}" target="_blank" rel="noopener"
                   class="mt-4 inline-block rounded-xl bg-[#06c755] px-5 py-3 font-extrabold text-white hover:brightness-110">{{ __('加入 LINE 好友') }}</a>
            </div>
        </section>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>

<script>
    document.querySelectorAll('[data-line-qr]').forEach((el) => {
        if (typeof QRCode === 'undefined') { el.textContent = el.dataset.url; return; }
        new QRCode(el, { text: el.dataset.url, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
        el.querySelectorAll('img, canvas').forEach((n) => { n.style.width = '100%'; n.style.height = 'auto'; });
    });
</script>
@endsection
