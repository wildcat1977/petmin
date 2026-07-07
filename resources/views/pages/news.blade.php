@extends('layouts.app')

@section('title', '最新消息 · Hazdar 災情回報')

@section('content')
<div class="min-h-dvh bg-[#002923] text-slate-200">
    @include('partials.nav')
    <div class="mx-auto max-w-2xl px-4 pb-16 pt-6">
        <h1 class="text-xl font-bold text-slate-100">📰 {{ __('最新消息') }}</h1>
        <p class="mt-1 text-xs text-slate-500">{{ __('停班停課、居家災防、市府與氣象署公告、災情通報') }}</p>

        <div class="mt-6 space-y-4">
            @forelse ($items as $item)
                @php
                    $badge = match ($item['category']) {
                        'school_closure' => 'bg-amber-500/15 text-amber-300',
                        'home_safety' => 'bg-cyan-500/15 text-cyan-300',
                        'gov_press' => 'bg-sky-500/15 text-sky-300',
                        'incident' => 'bg-rose-500/15 text-rose-300',
                        default => 'bg-white/10 text-slate-300',
                    };
                @endphp
                <article class="rounded-2xl border border-white/10 bg-[#161b22] p-5 {{ $item['is_pinned'] ? 'ring-1 ring-cyan-500/40' : '' }}">
                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                        @if ($item['is_pinned'])<span>📌</span>@endif
                        @if ($item['category'])
                            <span class="rounded-full px-2 py-0.5 font-semibold {{ $badge }}">{{ __(\App\Models\Announcement::CATEGORY_LABELS[$item['category']] ?? $item['category']) }}</span>
                        @endif
                        <span class="text-slate-500">{{ $item['published_at'] }}</span>
                    </div>
                    @if ($item['title'])<h2 class="mt-2 font-bold text-slate-100">{{ $item['title'] }}</h2>@endif
                    @if ($item['body'])
                        <div class="prose prose-sm prose-invert mt-2 max-w-none text-slate-300">{!! $item['body'] !!}</div>
                    @else
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-300">{{ $item['content'] }}</p>
                    @endif
                    @if ($item['link_url'])
                        <a href="{{ $item['link_url'] }}" target="_blank" rel="noopener"
                           class="mt-3 inline-block text-xs text-cyan-400 hover:text-cyan-300">🔗 {{ __('相關連結') }} ↗</a>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-500">{{ __('目前沒有消息。') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
