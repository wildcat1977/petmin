@extends('layouts.app')
@section('title', '交通與清運資訊 · Hazdar')
@section('content')
@php
    use App\Support\CityInfo;
    $closure = CityInfo::closure();
    $pumps = CityInfo::pumpStations();
    $gates = CityInfo::floodgates();
    $sanitation = CityInfo::manualSanitation();
    $openShelters = CityInfo::openShelterCount();
    $transit = CityInfo::transitBySystem();
@endphp
<div class="min-h-dvh bg-radar-bg text-slate-200">
    @include('partials.site-header')
    <div class="mx-auto max-w-4xl px-4 py-8">
        <h1 class="mb-6 text-2xl font-bold text-white">🚦 交通與清運資訊</h1>

        {{-- 停班停課 --}}
        @include('partials.city-info.closure', ['closure' => $closure])

        {{-- 大眾交通（Phase 2 才有內容；空則不顯示整段）--}}
        @include('partials.city-info.transit', ['transit' => $transit])

        {{-- 水門與抽水站 --}}
        @include('partials.city-info.water', ['pumps' => $pumps, 'gates' => $gates])

        {{-- 清運與環保局（手動）--}}
        @include('partials.city-info.sanitation', ['links' => $sanitation])

        {{-- 避難收容所 --}}
        <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
            <h2 class="mb-2 text-lg font-semibold text-slate-200">🏫 避難收容所</h2>
            <p class="text-sm text-slate-400">目前開設 <span class="font-bold text-emerald-400">{{ $openShelters }}</span> 處 ·
                <a href="{{ route('home') }}" class="text-cyan-300 underline underline-offset-2">在地圖查看 →</a></p>
        </section>
    </div>
    <div class="border-t border-white/10 px-4 py-6">@include('partials.footer-links')</div>
</div>
@endsection
