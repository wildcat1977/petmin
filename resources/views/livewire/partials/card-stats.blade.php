<div class="mt-2 grid grid-cols-2 gap-2">
    @foreach ($this->stats as $stat)
        <div class="rounded-lg border-l-2 border border-white/10 bg-black/30 p-3" style="border-color: {{ $stat['color'] }}">
            <p class="text-3xl font-black" style="color: {{ $stat['color'] }}">{{ $stat['count'] }}</p>
            <p class="mt-0.5 text-[11px] text-slate-400">{{ $stat['label'] }}</p>
            @if ($stat['delta'] > 0)
                <p class="text-[10px] font-semibold text-rose-400">+{{ $stat['delta'] }} 過去1H</p>
            @else
                <p class="text-[10px] text-slate-600">過去1H 無新增</p>
            @endif
        </div>
    @endforeach
</div>
