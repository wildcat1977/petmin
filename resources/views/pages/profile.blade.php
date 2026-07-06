@extends('layouts.app')

@section('title', __('我的回報 · CivicShield 災情回報'))

@section('content')
<div class="min-h-dvh bg-[#0d1117]">
    @include('partials.nav')

    <livewire:user-profile />
</div>
@endsection
