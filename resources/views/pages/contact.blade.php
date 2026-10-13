@extends('layouts.app')

@section('title', '聯絡管理員 · Petmin')

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-200">

    @include('partials.nav')

    <div class="flex min-h-[60vh] flex-col items-center justify-center px-4 text-center">
        <div class="mb-4 text-5xl">✉️</div>
        <h1 class="mb-2 text-2xl font-bold text-white">{{ __('聯絡管理員') }}</h1>
        <p class="text-sm text-slate-500">{{ __('有問題、建議或需要回報異常，歡迎直接留言') }}</p>
    </div>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>

</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        window.dispatchEvent(new CustomEvent('open-contact-form'));
    });
</script>
@endsection
