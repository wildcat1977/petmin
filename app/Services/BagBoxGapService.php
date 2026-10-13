<?php

namespace App\Services;

use App\Models\TrashBin;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 「有袋沒桶」：狗便清潔袋箱周圍 N 公尺內沒有 active 行人專用清潔箱。
 * 袋子裝完要丟哪？這是提案第一張圖，純資料 join 算出來。
 */
class BagBoxGapService
{
    /**
     * @return Collection<int, array{id: int, name: string, district: ?string, lat: float, lng: float, box_count: int, nearest_m: ?int}>
     */
    public function gaps(?int $meters = null): Collection
    {
        $meters ??= (int) config('petmin.bag_box_gap_meters');

        return Cache::remember("demand.gaps.{$meters}", 600, function () use ($meters) {
            $cleanBoxes = TrashBin::query()->active()->kind(TrashBin::KIND_CLEAN_BOX)
                ->get(['id', 'latitude', 'longitude']);

            return TrashBin::query()->active()->kind(TrashBin::KIND_DOG_BAG_BOX)
                ->get(['id', 'address', 'district', 'latitude', 'longitude', 'box_count'])
                ->map(function (TrashBin $bag) use ($cleanBoxes, $meters) {
                    $bbox = Geo::bbox((float) $bag->latitude, (float) $bag->longitude, $meters * 3);
                    $nearest = $cleanBoxes
                        ->filter(fn ($b) => $b->latitude >= $bbox['south'] && $b->latitude <= $bbox['north']
                            && $b->longitude >= $bbox['west'] && $b->longitude <= $bbox['east'])
                        ->map(fn ($b) => Geo::distanceMeters((float) $bag->latitude, (float) $bag->longitude, (float) $b->latitude, (float) $b->longitude))
                        ->min();

                    return [
                        'id' => $bag->id,
                        'name' => $bag->address,
                        'district' => $bag->district,
                        'lat' => (float) $bag->latitude,
                        'lng' => (float) $bag->longitude,
                        'box_count' => $bag->box_count,
                        'nearest_m' => $nearest === null ? null : (int) round($nearest),
                    ];
                })
                ->filter(fn ($row) => $row['nearest_m'] === null || $row['nearest_m'] > $meters)
                ->sortByDesc('box_count')
                ->values();
        });
    }
}
