@extends('layouts.app')

@section('title', __('皮克敏榜 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg">
    @include('partials.nav')

    <livewire:leaderboard />

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
