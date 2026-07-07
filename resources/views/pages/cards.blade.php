@extends('layouts.app')

@section('title', '圖卡專區 · Hazdar 災情回報')

@section('content')
<div class="min-h-dvh bg-[#0d1117] text-slate-200" x-data="{ open: null }">
    @include('partials.nav')
    <div class="mx-auto max-w-2xl px-4 pb-16 pt-6">
        <h1 class="text-xl font-bold text-slate-100">🖼️ {{ __('圖卡專區') }}</h1>

        @foreach (\App\Models\PromoCard::GROUP_LABELS as $groupKey => $groupLabel)
            @php $cards = $groups[$groupKey] ?? collect(); @endphp
            <div class="mt-6">
                <h2 class="text-sm font-bold text-slate-300">{{ __($groupLabel) }}</h2>

                @if ($cards->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">{{ __('圖卡即將上線') }}</p>
                @else
                    <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach ($cards as $card)
                            <button type="button"
                                    @click="open = '{{ Storage::disk('public')->url($card->image_path) }}'"
                                    class="rounded-2xl border border-white/10 bg-[#161b22] p-2 text-left">
                                <img src="{{ Storage::disk('public')->url($card->image_path) }}"
                                     alt="{{ $card->title }}" loading="lazy"
                                     class="aspect-square w-full rounded-xl object-cover">
                                <p class="mt-2 truncate text-xs text-slate-300">{{ $card->title }}</p>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Lightbox --}}
    <div x-show="open" x-cloak
         class="fixed inset-0 z-[600] flex flex-col items-center justify-center gap-4 bg-black/90 p-4"
         @click="open = null">
        <img :src="open" @click.stop class="max-h-[80dvh] max-w-full rounded-xl object-contain">
        <a :href="open" download @click.stop
           class="rounded-full bg-white/10 px-4 py-2 text-sm text-slate-200 hover:bg-white/20">
            ⬇ {{ __('下載') }}
        </a>
    </div>
</div>
@endsection
