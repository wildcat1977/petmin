@php $s = $this->cityInfoSummary; @endphp
<div class="mt-2 space-y-1.5 text-sm">
    <div class="flex items-center justify-between">
        <span class="text-slate-400">停班停課</span>
        <span class="{{ $s['closureActive'] ? 'text-rose-300 font-semibold' : 'text-emerald-400' }}">
            {{ $s['closureActive'] ? '已發布' : '正常' }}
        </span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">抽水站運轉中</span>
        <span class="text-yellow-300 font-semibold">{{ $s['runningPumps'] }}</span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">避難收容所</span>
        <span class="text-emerald-400 font-semibold">{{ $s['shelters'] }}</span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">捷運</span>
        @php
            $statusTxt = fn (?int $status) => match ($status) {
                1 => '正常', 2 => '調整', 0 => '停駛', default => '—',
            };
            $statusCls = fn (?int $status) => match ($status) {
                1 => 'text-emerald-400', 2 => 'text-amber-300', 0 => 'text-rose-300', default => 'text-slate-400',
            };
        @endphp
        <span class="{{ $statusCls($s['metroStatus']) }} font-semibold">{{ $statusTxt($s['metroStatus']) }}</span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">公車</span>
        <span class="{{ $statusCls($s['busStatus']) }} font-semibold">{{ $statusTxt($s['busStatus']) }}</span>
    </div>
</div>
<a href="{{ route('city-info') }}" class="mt-3 block text-center text-xs text-cyan-300 hover:underline">查看完整交通與清運資訊 →</a>
