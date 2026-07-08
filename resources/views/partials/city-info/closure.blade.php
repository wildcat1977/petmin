@if ($closure)
    <section class="mb-6 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4">
        <h2 class="text-lg font-bold text-rose-300">⚠️ 停班停課</h2>
        <p class="mt-1 text-sm leading-relaxed text-slate-100">{{ $closure->headline }}</p>
        @if ($closure->effective_at)
            <p class="mt-1 text-xs text-slate-400">生效：{{ $closure->effective_at->timezone('Asia/Taipei')->format('m/d H:i') }}</p>
        @endif
    </section>
@else
    <section class="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="text-lg font-semibold text-slate-300">停班停課</h2>
        <p class="mt-1 text-sm text-emerald-400">目前正常上班上課</p>
    </section>
@endif
