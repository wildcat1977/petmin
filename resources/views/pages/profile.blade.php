@extends('layouts.app')

@section('title', __('我的回報 · Hazdar 災情回報'))

@section('content')
<div class="min-h-dvh bg-[#002923]">
    @include('partials.nav')

    <livewire:user-profile />
</div>
@endsection
