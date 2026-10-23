@extends('layouts.app')

@section('title', __('我的回報 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg">
    @include('partials.nav')

    {{-- 管理員後台入口（v0.3 二次回饋 item 4）--}}
    @if (auth()->user()->is_admin)
        <div class="mx-auto mt-4 max-w-2xl px-4">
            <a href="{{ route('filament.admin.pages.dashboard') }}"
               class="flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm font-medium text-amber-300 hover:bg-amber-500/15">
                🛠️ {{ __('進入後台管理') }}
            </a>
        </div>
    @endif

    <livewire:dog-card-collection />

    <livewire:user-profile />

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
