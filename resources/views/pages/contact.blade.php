@extends('layouts.app')

@section('title', '聯絡管理員 · Hazdar 災情回報')

@section('content')
<div class="min-h-dvh bg-[#002923] text-slate-200">

    @include('partials.nav')

    <div class="flex min-h-[60vh] flex-col items-center justify-center px-4 text-center">
        <div class="mb-4 text-5xl">✉️</div>
        <h1 class="mb-2 text-2xl font-bold text-white">{{ __('聯絡管理員') }}</h1>
        <p class="text-sm text-slate-500">{{ __('有問題、建議或需要回報異常，歡迎直接留言') }}</p>
    </div>

</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        window.dispatchEvent(new CustomEvent('open-contact-form'));
    });
</script>
@endsection
