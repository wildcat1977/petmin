@extends('layouts.app')

@section('title', __('通報列表 · Hazdar 災情回報'))

@section('content')
<div class="min-h-dvh bg-radar-bg">
    @include('partials.nav')

    <livewire:report-list />

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
