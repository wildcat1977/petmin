@extends('layouts.app')
@section('title', '交通與清運資訊 · Hazdar')
@section('content')
@php
    use App\Support\CityInfo;
    $closure = CityInfo::closure();
    $closureStale = CityInfo::closureSourceStale();
    $pumpsByDistrict = CityInfo::pumpStationsByDistrict();
    $gates = CityInfo::floodgates();
    $sanitation = CityInfo::manualSanitation();
    $openShelters = CityInfo::openShelterCount();
    $totalShelters = CityInfo::totalShelterCount();
    $mappableShelters = CityInfo::mappableShelterCount();
    $transit = CityInfo::transitBySystem();
@endphp
<div class="min-h-dvh bg-radar-bg text-slate-200">
    @include('partials.site-header')

    {{-- 登入引導 Modal（由共用 head nav 的登入鈕觸發 open-auth-onboarding，spec §12.16/PM 回饋）--}}
    <livewire:auth-onboarding />

    <div class="mx-auto max-w-4xl px-4 py-8">
        <h1 class="mb-6 text-2xl font-bold text-white">🚦 交通與清運資訊</h1>

        {{-- 停班停課 --}}
        @include('partials.city-info.closure', ['closure' => $closure, 'closureStale' => $closureStale])

        {{-- 大眾交通（Phase 2 才有內容；空則不顯示整段）--}}
        @include('partials.city-info.transit', ['transit' => $transit])

        {{-- 水門與抽水站 --}}
        @include('partials.city-info.water', ['pumpsByDistrict' => $pumpsByDistrict, 'gates' => $gates])

        {{-- 清運與環保局（手動）--}}
        @include('partials.city-info.sanitation', ['links' => $sanitation])

        {{-- 避難收容所 --}}
        <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
            <h2 class="mb-2 text-lg font-semibold text-slate-200">🏫 避難收容所</h2>
            <p class="text-sm text-slate-400">全市避難收容所 <span class="font-bold text-emerald-400">{{ $totalShelters }}</span> 處 ·
                可定位 <span class="font-bold text-emerald-400">{{ $mappableShelters }}</span> 處
                @if ($openShelters > 0)
                    · 目前開設 <span class="font-bold text-emerald-400">{{ $openShelters }}</span> 處
                @endif
                · <a href="{{ route('home') }}" class="text-cyan-300 underline underline-offset-2">在地圖查看 →</a></p>
        </section>
    </div>
    <div class="border-t border-white/10 px-4 py-6">@include('partials.footer-links')</div>
</div>
@endsection
