@once
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    @endpush
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
    @endpush
@endonce

@php
    $lat = (float) ($record?->latitude  ?? 25.0330);
    $lng = (float) ($record?->longitude ?? 121.5654);

    // 附近通報：僅在編輯現有記錄時抓取
    $nearbyReports = [];
    if ($record?->id) {
        $delta = 0.003; // 約 300m
        $nearbyReports = \App\Models\Report::where('id', '!=', $record->id)
            ->whereBetween('latitude',  [$lat - $delta, $lat + $delta])
            ->whereBetween('longitude', [$lng - $delta, $lng + $delta])
            ->select('id', 'latitude', 'longitude', 'status', 'category', 'address', 'image_path', 'description', 'created_at')
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'status'     => $r->status,
                'category'   => $r->category,
                'address'    => $r->address ?? '',
                'image_url'  => $r->image_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($r->image_path)
                    : null,
                'desc'       => $r->description ? mb_substr($r->description, 0, 20) : '',
                'created_at' => $r->created_at?->format('Y-m-d') ?? '',
            ])
            ->values()
            ->toArray();
    }
@endphp

<div
    x-data="mapPickerInit_{{ $record?->id ?? 0 }}()"
    class="mb-1"
    wire:ignore
>
    <div x-ref="mapEl"
         style="height: 280px; border-radius: 0.5rem; overflow: hidden; border: 1px solid rgba(156,163,175,0.3); z-index: 0;">
    </div>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        拖曳圖釘調整正確位置，儲存後系統自動重建行政區地址。
        @if(!empty($nearbyReports))
            <span class="ml-2 text-amber-500">⚠ 附近 300m 內有 {{ count($nearbyReports) }} 筆相關通報（圓點標示）</span>
        @endif
    </p>
    <div class="mt-1 text-xs flex gap-3">
        <a
            :href="'https://www.google.com/maps?q=' + currentLat + ',' + currentLng"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1 text-primary-500 hover:text-primary-700 underline"
        >🗺 Google 地圖</a>
        <a
            :href="'https://www.google.com/maps?q=&layer=c&cbll=' + currentLat + ',' + currentLng"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1 text-primary-500 hover:text-primary-700 underline"
        >📷 街景</a>
    </div>
</div>

<script>
function mapPickerInit_{{ $record?->id ?? 0 }}() {
    return {
        map: null,
        marker: null,
        currentLat: {{ $lat }},
        currentLng: {{ $lng }},
        init() {
            const tryInit = () => {
                if (typeof L === 'undefined') { setTimeout(tryInit, 100); return; }

                this.map = L.map(this.$refs.mapEl, {
                    zoomControl: true,
                    attributionControl: false,
                }).setView([{{ $lat }}, {{ $lng }}], 17);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    subdomains: 'abc',
                    maxZoom: 19,
                }).addTo(this.map);

                this.marker = L.marker([{{ $lat }}, {{ $lng }}], { draggable: true }).addTo(this.map);

                this.marker.on('dragend', () => {
                    const ll = this.marker.getLatLng();
                    const lat = parseFloat(ll.lat.toFixed(7));
                    const lng = parseFloat(ll.lng.toFixed(7));
                    this.currentLat = lat;
                    this.currentLng = lng;
                    this.$wire.set('data.latitude',  lat);
                    this.$wire.set('data.longitude', lng);
                });

                const nearby = @js($nearbyReports);
                if (nearby.length > 0) {
                    const statusColor = {
                        pending:  '#f97316',
                        approved: '#22c55e',
                        rejected: '#6b7280',
                    };
                    const statusLabel = {
                        pending:  '待審核',
                        approved: '已核准',
                        rejected: '已拒絕',
                    };
                    const categoryLabel = @js(\App\Models\ReportCategory::labels());

                    nearby.forEach(r => {
                        let html = '';
                        if (r.image_url) {
                            html += '<img src="' + r.image_url + '" style="width:120px;height:80px;object-fit:cover;border-radius:4px;display:block;margin-bottom:4px;">';
                        }
                        html += '<b>#' + r.id + ' ' + (categoryLabel[r.category] || r.category) + '</b><br>';
                        html += (statusLabel[r.status] || r.status) + '<br>';
                        if (r.desc) {
                            html += '<span style="color:#888">' + r.desc + (r.desc.length >= 20 ? '…' : '') + '</span><br>';
                        }
                        if (r.address) {
                            html += '<span style="font-size:11px">' + r.address + '</span><br>';
                        }
                        html += '<small>' + r.created_at + '</small>';

                        L.circleMarker([r.lat, r.lng], {
                            radius:      8,
                            fillColor:   statusColor[r.status] || '#6b7280',
                            color:       '#fff',
                            weight:      1.5,
                            opacity:     1,
                            fillOpacity: 0.85,
                        }).addTo(this.map)
                        .bindPopup(html, { maxWidth: 160 });
                    });
                }
            };
            tryInit();
        }
    };
}
</script>
