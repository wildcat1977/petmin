<div class="mt-2 space-y-1.5">
    {{-- 警戒文字換行顯示，不用 truncate 裁掉看不到的後半段（PM 四次回饋：同 NCDR
         高溫/豪雨警戒常見的長文案問題，比照全站其他長文字元件的既有換行慣例）。 --}}
    @forelse ($this->alerts as $alert)
        <div class="flex items-start gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-1.5">
            <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ match ($alert['severity']) {
                'Extreme', 'Severe' => 'bg-rose-400 shadow-[0_0_6px_rgba(244,63,94,0.8)]',
                'Moderate' => 'bg-yellow-400 shadow-[0_0_6px_rgba(250,204,21,0.8)]',
                default => 'bg-sky-400',
            } }}"></span>
            <p class="min-w-0 flex-1 text-[10px] leading-relaxed text-slate-500">
                <span class="text-xs font-semibold text-slate-200">{{ $alert['type'] }}</span>
                {{ $alert['headline'] }}
            </p>
        </div>
    @empty
        <div class="flex items-center gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-2">
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span class="text-xs text-slate-400">目前無涵蓋台北市之有效警戒</span>
        </div>
    @endforelse
</div>
