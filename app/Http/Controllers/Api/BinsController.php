<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FarSignal;
use App\Models\TrashBin;
use App\Services\WalkingDistanceService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 桶位 API：
 *   GET /api/map/bins?bbox=W,S,E,N&zoom=&kinds=clean_box,dog_bag_box&statuses=active,missing
 *     zoom ≥ cluster_below_zoom 回個別桶；低 zoom 依 geohash 聚合（同 MapPointsController 的做法）
 *   GET /api/map/bins?mode=points&kinds=&statuses=  → 不分 zoom、不需 bbox，一次回全部個別桶
 *     （主地圖改在前端依像素距離聚合，聚合點才會貼著它代表的桶）
 *   GET /api/map/bins/nearby?lat=&lng=  → 回報表單吸附用，snap 距離內最近的桶
 *   GET /api/map/bins/find?lat=&lng=    → 我找垃圾桶：最近幾個可投垃圾的清潔箱，依步行距離排序
 */
class BinsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'in:points'],
            'bbox' => ['required_unless:mode,points', 'nullable', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'zoom' => ['required_unless:mode,points', 'nullable', 'integer', 'between:1,22'],
            'kinds' => ['nullable', 'string'],
            'statuses' => ['nullable', 'string'],
        ]);

        $allPoints = ($validated['mode'] ?? null) === 'points';
        [$west, $south, $east, $north] = isset($validated['bbox'])
            ? array_map('floatval', explode(',', $validated['bbox']))
            : [-180.0, -90.0, 180.0, 90.0];
        if ($west >= $east || $south >= $north) {
            return response()->json(['message' => 'invalid bbox'], 422);
        }
        $zoom = $allPoints ? PHP_INT_MAX : (int) $validated['zoom'];
        $kinds = collect(explode(',', $validated['kinds'] ?? ''))->intersect(array_keys(TrashBin::KIND_LABELS))->values();
        if ($kinds->isEmpty()) {
            $kinds = collect(array_keys(TrashBin::KIND_LABELS));
        }
        $statuses = collect(explode(',', $validated['statuses'] ?? ''))->intersect(array_keys(TrashBin::STATUS_LABELS))->values();
        if ($statuses->isEmpty()) {
            $statuses = collect([TrashBin::STATUS_ACTIVE]);
        }

        $cacheKey = sprintf('map.bins.%s.%d.%s.%s',
            implode(',', array_map(fn ($v) => round($v, 3), [$west, $south, $east, $north])),
            $zoom, $kinds->implode('-'), $statuses->implode('-'));

        $payload = Cache::remember($cacheKey, (int) config('petmin.map.cache_seconds'), function () use ($west, $south, $east, $north, $zoom, $kinds, $statuses) {
            $base = TrashBin::query()
                ->whereIn('kind', $kinds)
                ->whereIn('status', $statuses)
                ->whereBetween('latitude', [$south, $north])
                ->whereBetween('longitude', [$west, $east]);

            if ($zoom >= (int) config('petmin.map.cluster_below_zoom')) {
                $limit = (int) config('petmin.map.max_points');
                $bins = $base->limit($limit + 1)->get(['id', 'kind', 'status', 'address', 'district', 'latitude', 'longitude', 'box_count', 'last_verified_at', 'reports_count', 'removed_from_source_at']);

                return [
                    'mode' => 'points',
                    'truncated' => $bins->count() > $limit,
                    'points' => $bins->take($limit)->map(fn (TrashBin $b) => [
                        'id' => $b->id,
                        'kind' => $b->kind,
                        'status' => $b->status,
                        'name' => $b->address,
                        'district' => $b->district,
                        'lat' => (float) $b->latitude,
                        'lng' => (float) $b->longitude,
                        'n' => $b->box_count,
                        'reports' => $b->reports_count,
                        'verified_at' => $b->last_verified_at?->diffForHumans(),
                        'removed_at' => $b->removed_from_source_at?->toDateString(),
                    ])->values()->all(),
                ];
            }

            $precision = match (true) {
                $zoom <= 10 => 4,
                $zoom <= 12 => 5,
                default => 6,
            };
            $rows = $base->selectRaw("substr(geohash7, 1, {$precision}) as cell, kind, count(*) as n, avg(latitude) as lat, avg(longitude) as lng")
                ->groupBy('cell', 'kind')->get();

            return [
                'mode' => 'clusters',
                'truncated' => false,
                // 點群放在桶的實際重心（各 kind 依筆數加權），不放格中心——格中心會排成棋盤、放大後位置對不上
                'clusters' => $rows->groupBy('cell')->map(function ($group) {
                    $count = (int) $group->sum('n');

                    return [
                        'lat' => round($group->sum(fn ($row) => (float) $row->lat * $row->n) / $count, 5),
                        'lng' => round($group->sum(fn ($row) => (float) $row->lng * $row->n) / $count, 5),
                        'count' => $count,
                        'kind' => $group->sortByDesc('n')->first()->kind,
                    ];
                })->values()->all(),
            ];
        });

        return response()->json($payload);
    }

    /**
     * 我找垃圾桶：只找清潔箱（狗便袋箱是拿袋子的，不能丟）、排除已消失的桶。
     * 搜尋半徑由小到大擴張到湊滿候選數，先用直線距離挑候選，再換算步行距離排序；
     * 路網失敗時用直線 × 係數估算，distance_mode 標成 estimated 讓前端顯示「約」。
     */
    public function find(Request $request, WalkingDistanceService $walking): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $want = (int) config('petmin.find_bin.candidates');

        $candidates = collect();
        foreach (config('petmin.find_bin.search_radii_m') as $radius) {
            $bbox = Geo::bbox($lat, $lng, $radius);
            $candidates = TrashBin::query()
                ->where('kind', TrashBin::KIND_CLEAN_BOX)
                ->whereIn('status', [TrashBin::STATUS_ACTIVE, TrashBin::STATUS_UNVERIFIED])
                ->whereBetween('latitude', [$bbox['south'], $bbox['north']])
                ->whereBetween('longitude', [$bbox['west'], $bbox['east']])
                ->get(['id', 'external_id', 'status', 'address', 'district', 'latitude', 'longitude', 'last_verified_at'])
                ->map(fn (TrashBin $b) => ['bin' => $b, 'straight_m' => Geo::distanceMeters($lat, $lng, (float) $b->latitude, (float) $b->longitude)])
                ->filter(fn ($row) => $row['straight_m'] <= $radius)
                ->sortBy('straight_m')
                ->take($want)
                ->values();
            if ($candidates->count() >= $want) {
                break;
            }
        }

        $walkMeters = $walking->distancesFrom($lat, $lng, $candidates->map(fn ($row) => [
            'lat' => (float) $row['bin']->latitude,
            'lng' => (float) $row['bin']->longitude,
        ])->all());
        $mode = $walkMeters === null ? FarSignal::MODE_ESTIMATED : FarSignal::MODE_WALKING;
        $perMinute = (float) config('petmin.find_bin.walking.meters_per_minute');
        $detour = (float) config('petmin.find_bin.walking.estimate_detour_factor');

        $results = $candidates
            // 步行模式下路網接不到的桶，也用直線 × 係數估一個值，排序才不會亂
            ->map(function ($row, $i) use ($walkMeters, $detour) {
                $row['walk_m'] = $walkMeters[$i] ?? $row['straight_m'] * $detour;

                return $row;
            })
            ->sortBy('walk_m')
            ->take((int) config('petmin.find_bin.results'))
            ->map(function ($row) use ($perMinute) {
                $bin = $row['bin'];

                return [
                    'id' => $bin->id,
                    // 打卡頁 /b/{code} 用的編號：官方編號，市民新增的桶才用 id
                    'code' => $bin->external_id ?? (string) $bin->id,
                    'name' => $bin->address,
                    'district' => $bin->district,
                    'lat' => (float) $bin->latitude,
                    'lng' => (float) $bin->longitude,
                    'distance_m' => (int) round($row['walk_m']),
                    'straight_m' => (int) round($row['straight_m']),
                    'walk_minutes' => max(1, (int) ceil($row['walk_m'] / $perMinute)),
                    // 官方桶大多沒人實地回報過，標「待驗證」沒有區別作用；改給「最近一次有人確認還在」的天數
                    'confirmed_days_ago' => $bin->last_verified_at ? (int) $bin->last_verified_at->diffInDays(now()) : null,
                    'nav_url' => 'https://www.google.com/maps/dir/?api=1&travelmode=walking&destination='.$bin->latitude.','.$bin->longitude,
                ];
            })
            ->values();

        return response()->json([
            'distance_mode' => $mode,
            'bins' => $results,
        ]);
    }

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $snap = (int) config('petmin.bin_snap_meters');
        $bbox = Geo::bbox($lat, $lng, $snap);

        $nearest = TrashBin::query()
            ->whereIn('status', [TrashBin::STATUS_ACTIVE, TrashBin::STATUS_UNVERIFIED])
            ->whereBetween('latitude', [$bbox['south'], $bbox['north']])
            ->whereBetween('longitude', [$bbox['west'], $bbox['east']])
            ->get(['id', 'kind', 'status', 'address', 'latitude', 'longitude'])
            ->map(fn (TrashBin $b) => ['bin' => $b, 'm' => Geo::distanceMeters($lat, $lng, (float) $b->latitude, (float) $b->longitude)])
            ->filter(fn ($row) => $row['m'] <= $snap)
            ->sortBy('m')
            ->first();

        if (! $nearest) {
            return response()->json(['bin' => null]);
        }

        return response()->json(['bin' => [
            'id' => $nearest['bin']->id,
            'kind' => $nearest['bin']->kind,
            'kind_label' => TrashBin::KIND_LABELS[$nearest['bin']->kind],
            'name' => $nearest['bin']->address,
            'lat' => (float) $nearest['bin']->latitude,
            'lng' => (float) $nearest['bin']->longitude,
            'distance_m' => (int) round($nearest['m']),
        ]]);
    }
}
