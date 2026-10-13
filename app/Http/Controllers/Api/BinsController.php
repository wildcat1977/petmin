<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrashBin;
use App\Support\Geo;
use App\Support\Geohash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 桶位 API：
 *   GET /api/map/bins?bbox=W,S,E,N&zoom=&kinds=clean_box,dog_bag_box&statuses=active,missing
 *     zoom ≥ cluster_below_zoom 回個別桶；低 zoom 依 geohash 聚合（同 MapPointsController 的做法）
 *   GET /api/map/bins/nearby?lat=&lng=  → 回報表單吸附用，snap 距離內最近的桶
 */
class BinsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['required', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'zoom' => ['required', 'integer', 'between:1,22'],
            'kinds' => ['nullable', 'string'],
            'statuses' => ['nullable', 'string'],
        ]);

        [$west, $south, $east, $north] = array_map('floatval', explode(',', $validated['bbox']));
        if ($west >= $east || $south >= $north) {
            return response()->json(['message' => 'invalid bbox'], 422);
        }
        $zoom = (int) $validated['zoom'];
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
                $bins = $base->limit($limit + 1)->get(['id', 'kind', 'status', 'address', 'district', 'latitude', 'longitude', 'box_count', 'last_verified_at', 'reports_count']);

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
                    ])->values()->all(),
                ];
            }

            $precision = match (true) {
                $zoom <= 10 => 4,
                $zoom <= 12 => 5,
                default => 6,
            };
            $rows = $base->selectRaw("substr(geohash7, 1, {$precision}) as cell, kind, count(*) as n")
                ->groupBy('cell', 'kind')->get();

            return [
                'mode' => 'clusters',
                'truncated' => false,
                'clusters' => $rows->groupBy('cell')->map(function ($group, $cell) {
                    $center = Geohash::decodeCenter($cell);

                    return [
                        'lat' => round($center['lat'], 5),
                        'lng' => round($center['lng'], 5),
                        'count' => (int) $group->sum('n'),
                        'kind' => $group->sortByDesc('n')->first()->kind,
                    ];
                })->values()->all(),
            ];
        });

        return response()->json($payload);
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
