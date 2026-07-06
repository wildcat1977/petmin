@extends('layouts.app')

@section('title', 'CivicShield EOC 戰情室')

@section('content')
{{-- Phase 1c 完整實作：左欄 CWA 即時 + 24H 趨勢 + NCDR 燈號；中央地圖；右欄統計 + 物資看板 --}}
<div class="flex min-h-dvh items-center justify-center">
    <div class="text-center">
        <p class="text-xs font-bold tracking-widest text-cyan-300">CIVICSHIELD</p>
        <h1 class="mt-2 text-2xl font-bold">{{ __('EOC 戰情室建置中') }}</h1>
        <a href="{{ route('home') }}" class="mt-4 inline-block text-sm text-slate-400 hover:text-slate-200">{{ __('← 返回災情地圖') }}</a>
    </div>
</div>
@endsection
