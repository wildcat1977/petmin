@extends('layouts.app')

@section('title', __(':title · 垃圾桶圖鑑 · Petmin', ['title' => $card['title']]))
@section('og_title', __('我的狗狗抽到了「:title」', ['title' => $card['title']]))
@section('og_description', __('散步時順手檢查附近的垃圾桶，回報就能抽扭蛋卡。帶你家狗狗一起出任務！'))
@section('og_image', asset('gacha/'.($card['share_image'] ?? 'share-honest-pooper.jpg')))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    {{-- 分享出去的卡片頁：朋友點連結看到這張卡，再被邀請一起出任務 --}}
    <main class="mx-auto max-w-md px-4 py-8 text-center">
        <p class="text-sm font-bold text-amber-300">{{ __('有一隻狗狗抽到了一張卡') }}</p>
        <div class="mx-auto mt-4 w-56 -rotate-3 rounded-3xl border-4 border-slate-900 bg-amber-50 p-5 shadow-[0_0_0_6px_#ffd65a,0_16px_30px_rgba(0,0,0,.4)]">
            <img src="/gacha/{{ $card['image'] }}.webp" alt="{{ $card['title'] }}" class="mx-auto h-auto w-4/5">
        </div>
        <h1 class="mt-6 text-2xl font-extrabold">{{ $card['title'] }}</h1>
        <p class="mt-1 text-slate-300">{{ $card['line'] }}</p>

        <div class="mt-6 rounded-2xl bg-radar-card p-5 text-left">
            <p class="font-bold">{{ __('你家狗狗也來出任務吧！') }}</p>
            <p class="mt-1 text-sm text-slate-300">{{ __('散步時順手看看附近的垃圾桶，滿了、壞了、臭臭的就回報。每次回報都能抽一段扭蛋劇情，收集不一樣的卡片。') }}</p>
            <a href="{{ route('find') }}" class="mt-4 block rounded-xl bg-amber-400 px-4 py-3 text-center font-extrabold text-amber-950 hover:bg-amber-300">
                🐾 {{ __('帶狗狗一起出任務') }}
            </a>
        </div>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
