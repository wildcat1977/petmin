@extends('layouts.app')

@section('title', __('怎麼使用 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    {{-- LINE 選單「怎麼使用」：給養狗的人的任務說明 --}}
    <main class="mx-auto max-w-xl px-4 py-6">
        <div class="flex items-center gap-4">
            <img src="/gacha/dog-happy.webp" alt="" class="h-24 w-auto">
            <div>
                <h1 class="text-2xl font-bold">{{ __('小狗巡桶任務') }}</h1>
                <p class="mt-1 text-sm text-slate-300">{{ __('散步時順手看看附近的垃圾桶，讓城市更乾淨，狗狗也能收集卡片。') }}</p>
            </div>
        </div>

        <ol class="mt-6 flex flex-col gap-3">
            <li class="flex gap-4 rounded-2xl bg-radar-card p-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-400 font-extrabold text-amber-950">1</span>
                <div>
                    <h2 class="font-bold">{{ __('手上有垃圾，找最近的桶') }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ __('按選單「我找垃圾桶」，列出最近的 3 個，按「帶我去」就能步行導航。太遠就按「好遠！」，我們會記下這裡需要一個桶。') }}</p>
                </div>
            </li>
            <li class="flex gap-4 rounded-2xl bg-radar-card p-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-400 font-extrabold text-amber-950">2</span>
                <div>
                    <h2 class="font-bold">{{ __('經過垃圾桶，回報它的狀況') }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ __('按選單「回報垃圾桶狀況」，選身邊的桶，拍一張照片，選「垃圾桶壞了」「臭臭的」「垃圾桶滿了！」或「桶還在」。') }}</p>
                </div>
            </li>
            <li class="flex gap-4 rounded-2xl bg-radar-card p-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-400 font-extrabold text-amber-950">3</span>
                <div class="min-w-0">
                    <h2 class="font-bold">{{ __('看到 Petmin 貼紙？碰一下就佔領垃圾桶') }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ __('有貼紙的垃圾桶，用手機碰一下貼紙或掃 QR Code，會跳出今日任務動畫，直接回報這個桶。') }}</p>
                    <img src="/gacha/stamp.webp" alt="" class="mt-3 h-24 w-auto">
                </div>
            </li>
            <li class="flex gap-4 rounded-2xl bg-radar-card p-4">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-400 font-extrabold text-amber-950">4</span>
                <div>
                    <h2 class="font-bold">{{ __('每次回報都抽一張扭蛋卡') }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ __('回報完會播一段隨機的扭蛋劇情，抽到的卡收進「我的垃圾桶圖鑑」，也可以分享給朋友，邀他們的狗狗一起出任務。') }}</p>
                </div>
            </li>
        </ol>

        <div class="mt-6 grid grid-cols-2 gap-3">
            <a href="{{ route('find') }}" class="rounded-xl bg-emerald-500 px-4 py-3 text-center font-bold text-emerald-950 hover:bg-emerald-400">🗑️ {{ __('我找垃圾桶') }}</a>
            <a href="{{ route('report-bin') }}" class="rounded-xl bg-amber-400 px-4 py-3 text-center font-bold text-amber-950 hover:bg-amber-300">🐾 {{ __('回報垃圾桶狀況') }}</a>
        </div>

        <p class="mt-6 text-xs text-slate-500">
            {{ __('回報會變成「哪裡需要垃圾桶」「垃圾桶多快會滿」的公開資料，任何人都可以在') }}
            <a href="{{ route('candidates') }}" class="underline">{{ __('Petmin 網站') }}</a>{{ __('看到。') }}
        </p>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
