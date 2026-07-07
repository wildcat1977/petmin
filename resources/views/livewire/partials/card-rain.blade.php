{{--
    $withChart：是否渲染圖表；$chartId：canvas id（桌機/行動版同時掛載時需給不同 id，
    避免 #rain-trend-chart 重複——圖表本身由 app.js 的 ResizeObserver 延遲初始化，
    只在容器實際有版面大小時才建立/resize，容器隱藏中不會畫出空白圖（v0.3 二次回饋 item 7）。
--}}
@if ($withChart ?? true)
    <div wire:ignore class="mt-2 h-28">
        <canvas id="{{ $chartId ?? 'rain-trend-chart' }}" data-trend='@json($this->rainTrend)'></canvas>
    </div>
@endif
<p class="mt-1 text-center text-[10px] text-slate-500">{{ __('累積') }} {{ number_format($this->rainAccumulated, 1) }} mm</p>
