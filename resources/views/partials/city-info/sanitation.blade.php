@if ($links->isNotEmpty())
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="mb-3 text-lg font-semibold text-slate-200">🗑️ 清運與環保局</h2>
        @foreach ($links as $l)
            <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
                <a href="{{ $l->url }}" class="text-cyan-300 hover:underline">{{ $l->label }}</a>
                @if ($l->status_text)<span class="text-xs text-slate-400">{{ $l->status_text }}</span>@endif
            </div>
        @endforeach
    </section>
@endif
