{{-- $withChart：桌機欄渲染圖表；行動版只顯示累積數字，避免 #rain-trend-chart 這個 DOM id 重複。 --}}
@if ($withChart ?? true)
    <div wire:ignore class="mt-2 h-28">
        <canvas id="rain-trend-chart" data-trend='@json($this->rainTrend)'></canvas>
    </div>
@endif
<p class="mt-1 text-center text-[10px] text-slate-500">{{ __('累積') }} {{ number_format($this->rainAccumulated, 1) }} mm</p>
