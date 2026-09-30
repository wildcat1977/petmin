<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** EOC dashboard 開啟行政區界疊層 */
    public bool $showDistricts = false;

    /**
     * 分類視覺 meta（含停用：既有點位仍需上色/標籤），注入 data-categories
     * 供 app.js 於 runtime 建 CATEGORY_META（spec §11.7）。
     *
     * @return list<array{slug: string, name: string, emoji: ?string, color: string, active: bool, extra: ?array{label: string, unit: string}}>
     */
    public function getCategoryMetaProperty(): array
    {
        return \App\Models\ReportCategory::allOrdered()->map(fn ($cat) => [
            'slug' => $cat->slug,
            'name' => __($cat->name),
            'emoji' => $cat->emoji,
            'color' => $cat->color,
            'active' => $cat->is_active,
            'extra' => $cat->extra_field ? [
                'label' => __($cat->extra_field['label']),
                'unit' => $cat->extra_field['unit'] ?? '',
            ] : null,
        ])->values()->all();
    }

    #[On('report-submitted')]
    public function refreshMap(): void
    {
        // 點位改由前端向 bbox API 取，只需通知 JS 重新抓取
        $this->dispatch('hazdar:reports-updated');
    }
}; ?>

<div
    id="radar-map"
    wire:ignore
    class="absolute inset-0 z-0"
    data-default-lat="{{ config('hazdar.default_lat') }}"
    data-default-lng="{{ config('hazdar.default_lng') }}"
    data-default-zoom="{{ config('hazdar.default_zoom') }}"
    data-reports-url="{{ route('api.map.reports') }}"
    data-shelters-url="{{ route('api.map.shelters') }}"
    data-radar-echo-url="{{ route('api.radar-echo') }}"
    data-radar-echo-bounds='@json(config('hazdar.sources.radar_echo.bounds'))'
    data-flood-potential='@json(config('hazdar.flood_potential'))'
    data-categories='@json($this->categoryMeta)'
    @if ($showDistricts) data-districts-url="{{ asset('geo/taipei-districts.json') }}" @endif
></div>
