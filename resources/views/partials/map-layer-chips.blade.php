{{--
    地圖圖層 checkbox 清單，桌機（右上「圖層」面板）與手機右側面板共用同一份，
    data-map-chip 由 app.js initChips 綁定；同 key 的所有元素狀態同步。
    每組包在 layer-check-section 裡，桌機面板才能分兩欄而不把組名跟選項拆開。
--}}
{{-- 分組小標：「桶的狀態」與「民眾回報」分開，避免「已確認消失」（桶）和「桶消失了」（回報分類）被看成同一件事 --}}
<div class="layer-check-section">
    <span class="layer-check-group">{{ __('桶位') }}</span>
    <button type="button" data-map-chip="bins" style="--chip-color:#22c55e" class="layer-check chip-on">🗑️ {{ __('清潔箱') }}</button>
    <button type="button" data-map-chip="bag_boxes" style="--chip-color:#38bdf8" class="layer-check chip-on">🐕 {{ __('狗便袋箱') }}</button>
    <button type="button" data-map-chip="missing_bins" style="--chip-color:#a78bfa" class="layer-check" title="{{ __('「桶消失了」回報經核准後，桶的狀態') }}">👻 {{ __('已確認消失') }}</button>
    <button type="button" data-map-chip="occupied_only" style="--chip-color:#f59e0b" class="layer-check" title="{{ __('只顯示被市民佔領命名的桶') }}">🚩 {{ __('只看被佔領的') }}</button>
    <button type="button" data-map-chip="gaps" style="--chip-color:#f472b6" class="layer-check">🧷 {{ __('有袋沒桶') }}</button>
</div>
<div class="layer-check-section">
    <span class="layer-check-group">{{ __('需求') }}</span>
    <button type="button" data-map-chip="demand" style="--chip-color:#f97316" class="layer-check chip-on">🔥 {{ __('需求熱力') }}</button>
</div>
{{-- 回報分類（對應圖例配色，來源 report_categories） --}}
<div class="layer-check-section">
    <span class="layer-check-group">{{ __('民眾回報') }}</span>
    @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
        <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                class="layer-check chip-on">{{ $cat->emoji }} {{ $cat->label() }}</button>
    @endforeach
</div>
{{-- 回報三態 --}}
<div class="layer-check-section">
    <span class="layer-check-group">{{ __('處理進度') }}</span>
    <button type="button" data-map-chip="reported" style="--chip-color:#22d3ee" class="layer-check chip-on">📍 {{ __('回報') }}</button>
    <button type="button" data-map-chip="notified" style="--chip-color:#818cf8" class="layer-check chip-on">📣 {{ __('已通報') }}</button>
    <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8" class="layer-check chip-on">✅ {{ __('已處理') }}</button>
</div>
