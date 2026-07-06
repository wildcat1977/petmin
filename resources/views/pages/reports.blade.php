@extends('layouts.app')

@section('title', __('通報列表 · CivicShield 災情回報'))

@section('content')
<div class="min-h-dvh bg-[#0d1117]">
    @include('partials.nav')

    <livewire:report-list />
</div>
@endsection
