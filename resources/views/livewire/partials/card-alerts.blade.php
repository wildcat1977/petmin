<div class="mt-2 space-y-1.5">
    @forelse ($this->alerts as $alert)
        <div class="flex items-center gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-1.5">
            <span class="h-2 w-2 shrink-0 rounded-full {{ match ($alert['severity']) {
                'Extreme', 'Severe' => 'bg-rose-400 shadow-[0_0_6px_rgba(244,63,94,0.8)]',
                'Moderate' => 'bg-yellow-400 shadow-[0_0_6px_rgba(250,204,21,0.8)]',
                default => 'bg-sky-400',
            } }}"></span>
            <span class="text-xs font-semibold">{{ $alert['type'] }}</span>
            <span class="truncate text-[10px] text-slate-500">{{ $alert['headline'] }}</span>
        </div>
    @empty
        <div class="flex items-center gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-2">
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span class="text-xs text-slate-400">目前無涵蓋台北市之有效警戒</span>
        </div>
    @endforelse
</div>
