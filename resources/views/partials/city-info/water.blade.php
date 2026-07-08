@php
    $allPumps = collect($pumpsByDistrict)->flatten();
    $latestObservedAt = $allPumps->pluck('observed_at')->filter()->max();
@endphp
<section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
    <h2 class="mb-3 text-lg font-semibold text-slate-200">💧 水門與抽水站</h2>
    @forelse ($pumpsByDistrict as $district => $stations)
        <div x-data="{ open: false }" class="border-b border-white/5 py-1 last:border-0">
            <button type="button" @click="open = ! open"
                    class="flex w-full items-center justify-between py-1.5 text-left text-sm">
                <span class="text-slate-300">
                    {{ $district }}
                    <span class="text-xs text-slate-500">（{{ $stations->count() }} 站 · {{ $stations->filter->isRunning()->count() }} 運轉中）</span>
                </span>
                <span class="text-slate-500" x-text="open ? '▲' : '▼'"></span>
            </button>
            <div x-show="open" x-cloak class="pl-2">
                @foreach ($stations as $p)
                    <div class="flex items-center justify-between border-t border-white/5 py-1.5 text-sm">
                        <span class="text-slate-300">{{ $p->name }}</span>
                        <span class="flex items-center gap-2">
                            @if ($p->door_status)
                                <span class="text-xs text-slate-500">{{ $p->door_status }}</span>
                            @endif
                            <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $p->isRunning() ? 'bg-yellow-500/20 text-yellow-300' : 'bg-slate-500/20 text-slate-400' }}">
                                {{ $p->pumb_status ?? '—' }}
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500">目前無抽水站資料</p>
    @endforelse
    @if ($latestObservedAt)
        <p class="mt-2 text-xs text-slate-600">資料更新於 {{ $latestObservedAt->timezone('Asia/Taipei')->format('m/d H:i') }}</p>
    @endif
    <p class="mt-2 text-xs text-slate-500">疏散門位置 {{ $gates->count() }} 座（詳見地圖）</p>
</section>
