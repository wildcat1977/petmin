<?php

use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * 分類視覺 meta（含停用：既有點位仍需上色/標籤），注入 data-categories
     * 供 app.js 於 runtime 建 CATEGORY_META。
     *
     * @return list<array{slug: string, name: string, emoji: ?string, color: string, active: bool, extra: ?array{label: string, unit: string}}>
     */
    public function getCategoryMetaProperty(): array
    {
        return \App\Models\ReportCategory::allOrdered()->map(fn ($cat) => [
            'slug' => $cat->slug,
            'name' => $cat->label(),
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
        $this->dispatch('petmin:reports-updated');
    }
}; ?>

<div
    id="radar-map"
    wire:ignore
    class="absolute inset-0 z-0"
    data-default-lat="{{ config('petmin.default_lat') }}"
    data-default-lng="{{ config('petmin.default_lng') }}"
    data-default-zoom="{{ config('petmin.default_zoom') }}"
    data-reports-url="{{ route('api.map.reports') }}"
    data-bins-url="{{ route('api.map.bins') }}"
    data-gaps-url="{{ route('api.map.gaps') }}"
    data-demand-url="{{ route('api.map.demand') }}"
    data-demand-at-url="{{ route('api.map.demand.at') }}"
    data-categories='@json($this->categoryMeta)'
></div>
