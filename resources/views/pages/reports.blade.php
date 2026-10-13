@extends('layouts.app')

@section('title', __('回報清單 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg">
    @include('partials.nav')

    <livewire:report-list />

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
