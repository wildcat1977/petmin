@if ($closure)
    <section class="mb-6 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4">
        <h2 class="text-lg font-bold text-rose-300">⚠️ 停班停課</h2>
        <p class="mt-1 text-sm leading-relaxed text-slate-100">{{ $closure->headline }}</p>
        @if ($closure->effective_at)
            <p class="mt-1 text-xs text-slate-400">生效：{{ $closure->effective_at->timezone('Asia/Taipei')->format('m/d H:i') }}</p>
        @endif
    </section>
@elseif (! empty($closureStale))
    {{-- 來源延遲/中斷：不拍胸脯說「正常」，改提示以官方公告為準（事件期間來源延遲卻誤告正常有安全風險）--}}
    <section class="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
        <h2 class="text-lg font-semibold text-amber-300">停班停課</h2>
        <p class="mt-1 text-sm text-slate-100">停課資料來源更新延遲，請以
            <a href="https://www.dgpa.gov.tw/typh/daily/nds.html" target="_blank" rel="noopener"
               class="text-amber-200 underline">行政院人事行政總處官方公告</a>為準。</p>
    </section>
@else
    <section class="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="text-lg font-semibold text-slate-300">停班停課</h2>
        <p class="mt-1 text-sm text-emerald-400">目前正常上班上課</p>
    </section>
@endif
