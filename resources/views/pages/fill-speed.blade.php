@extends('layouts.app')

@section('title', __('垃圾桶多快會滿 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    <main class="mx-auto max-w-xl px-4 py-6">
        <h1 class="text-2xl font-bold">{{ __('垃圾桶多快會滿') }}</h1>
        <p class="mt-1 text-sm text-slate-300">{{ __('平均多久就滿一次，越快滿的桶越需要加收或加桶。') }}</p>

        {{-- 展示版：真實數據要累積回報才算得出來，這裡一律是示意數字 --}}
        <div class="mt-4 rounded-xl border-2 border-amber-400 bg-amber-400/10 px-4 py-3 text-sm text-amber-200">
            ⚠️ {{ __('以下是示意數字，不是實際統計。正式版會用同一個桶兩次「垃圾桶滿了！」回報的間隔來估算。') }}
        </div>

        <ol class="mt-5 flex flex-col gap-3">
            @foreach ($bins as $i => $bin)
                <li class="rounded-xl bg-radar-card p-4 shadow">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate font-bold">{{ $i + 1 }}. 🗑️ {{ $bin['name'] }}</span>
                        <span class="shrink-0 text-sm font-bold {{ $bin['hours'] <= 12 ? 'text-red-400' : ($bin['hours'] <= 36 ? 'text-amber-300' : 'text-emerald-300') }}">
                            {{ __('約 :h 小時滿一次', ['h' => $bin['hours']]) }}
                        </span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full {{ $bin['hours'] <= 12 ? 'bg-red-400' : ($bin['hours'] <= 36 ? 'bg-amber-300' : 'bg-emerald-400') }}"
                             style="width: {{ $bin['bar'] }}%"></div>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">{{ $bin['district'] }}・{{ __('示意') }}</p>
                </li>
            @endforeach
        </ol>

        <details class="mt-6 rounded-xl bg-radar-card p-4 text-sm text-slate-300">
            <summary class="cursor-pointer font-bold text-slate-100">{{ __('正式版怎麼算？') }}</summary>
            <p class="mt-2">{{ __('同一個桶，連續兩筆通過審核的「垃圾桶滿了！」回報，中間隔多久就是一次「滿的速度」；取最近幾次的平均。回報太少的桶會標「資料收集中」，不顯示數字。') }}</p>
        </details>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>
@endsection
