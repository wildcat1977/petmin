{{--
    地圖圖層 chips，桌機（map-chip）與手機面板（layer-check）共用同一份，
    data-map-chip 由 app.js initChips 綁定；同 key 的所有元素狀態同步。
    $cls = 'map-chip' | 'layer-check'
--}}
{{-- 桶位圖層 --}}
<button type="button" data-map-chip="bins" style="--chip-color:#22c55e" class="{{ $cls }} chip-on">🗑️ {{ __('清潔箱') }}</button>
<button type="button" data-map-chip="bag_boxes" style="--chip-color:#38bdf8" class="{{ $cls }} chip-on">🐕 {{ __('狗便袋箱') }}</button>
<button type="button" data-map-chip="missing_bins" style="--chip-color:#a78bfa" class="{{ $cls }}">👻 {{ __('消失的桶') }}</button>
<button type="button" data-map-chip="gaps" style="--chip-color:#f472b6" class="{{ $cls }}">🧷 {{ __('有袋沒桶') }}</button>
<button type="button" data-map-chip="demand" style="--chip-color:#f97316" class="{{ $cls }} chip-on">🔥 {{ __('需求熱力') }}</button>
{{-- 回報分類（對應圖例配色，來源 report_categories） --}}
@foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
    <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
            class="{{ $cls }} chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
@endforeach
{{-- 回報三態 --}}
<button type="button" data-map-chip="reported" style="--chip-color:#22d3ee" class="{{ $cls }} chip-on">📍 {{ __('回報') }}</button>
<button type="button" data-map-chip="notified" style="--chip-color:#818cf8" class="{{ $cls }} chip-on">📣 {{ __('已通報') }}</button>
<button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8" class="{{ $cls }} chip-on">✅ {{ __('已處理') }}</button>
