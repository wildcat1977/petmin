<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Support\Geohash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 點位 API（spec §7.2）——server-side bbox query，取代 ratdar 前端全量模式。
 *
 * zoom ≥ cluster_below_zoom：回傳個別點位（筆數上限 hazdar.map.max_points）
 * zoom 低：依 geohash（precision 隨 zoom 4~6）聚合回傳 {center, count, dominant_category}
 * 回應以 bbox+filter+zoom 為 key 快取 15~30 秒。
 */
class MapPointsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['required', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'zoom' => ['required', 'integer', 'between:1,22'],
            'categories' => ['nullable', 'string'],
            'show_resolved' => ['nullable', 'boolean'],
        ]);

        // bbox = west,south,east,north（Leaflet getBounds 順序）
        [$west, $south, $east, $north] = array_map('floatval', explode(',', $validated['bbox']));
        if ($west >= $east || $south >= $north) {
            return response()->json(['message' => 'invalid bbox'], 422);
        }

        $zoom = (int) $validated['zoom'];
        $categories = collect(explode(',', $validated['categories'] ?? ''))
            ->intersect(ReportCategory::slugs())
            ->values();
        if ($categories->isEmpty()) {
            $categories = collect(ReportCategory::slugs());
        }
        $showResolved = (bool) ($validated['show_resolved'] ?? true);

        // 快取 key：bbox 取 3 位小數（~110m）讓鄰近請求命中同一快取
        $cacheKey = sprintf(
            'map.reports.%s.%d.%s.%d',
            implode(',', array_map(fn ($v) => round($v, 3), [$west, $south, $east, $north])),
            $zoom,
            $categories->implode('-'),
            $showResolved ? 1 : 0,
        );

        $payload = Cache::remember(
            $cacheKey,
            (int) config('hazdar.map.cache_seconds'),
            function () use ($west, $south, $east, $north, $zoom, $categories, $showResolved) {
                $base = Report::query()
                    ->visible()
                    ->when(! $showResolved, fn ($q) => $q->unresolved())
                    ->whereIn('category', $categories)
                    ->whereBetween('latitude', [$south, $north])
                    ->whereBetween('longitude', [$west, $east]);

                if ($zoom >= (int) config('hazdar.map.cluster_below_zoom')) {
                    return $this->points($base);
                }

                return $this->clusters($base, $zoom);
            },
        );

        return response()->json($payload);
    }

    private function points($query): array
    {
        $limit = (int) config('hazdar.map.max_points');

        $reports = $query
            ->latest()
            ->limit($limit + 1)
            ->get(['id', 'category', 'extra_value', 'latitude', 'longitude',
                'image_path', 'description', 'status', 'verification_level', 'notified_at', 'resolved_at', 'created_at']);

        $truncated = $reports->count() > $limit;

        return [
            'mode' => 'points',
            'truncated' => $truncated,
            'points' => $reports->take($limit)->map(fn (Report $r) => array_filter([
                'id' => $r->id,
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'cat' => $r->category,
                'vl' => $r->verification_level,
                'st' => $r->resolved_at ? 'resolved' : ($r->notified_at ? 'notified' : null),
                'extra' => $r->extra_value,
                'img' => $r->publicImageUrl(),
                'desc' => $r->description ? Str::limit($r->description, 60) : null,
                'at' => $r->created_at->diffForHumans(),
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    private function clusters($query, int $zoom): array
    {
        // precision 隨 zoom：市級看大格，區級看小格（spec §7.2）
        $precision = match (true) {
            $zoom <= 10 => 4,
            $zoom <= 12 => 5,
            default => 6,
        };

        $rows = $query
            ->selectRaw("substr(geohash7, 1, {$precision}) as cell, category, count(*) as n")
            ->whereNotNull('geohash7')
            ->groupBy('cell', 'category')
            ->get();

        $clusters = $rows->groupBy('cell')->map(function ($group, $cell) {
            $center = Geohash::decodeCenter($cell);
            $dominant = $group->sortByDesc('n')->first();

            return [
                'lat' => round($center['lat'], 5),
                'lng' => round($center['lng'], 5),
                'count' => (int) $group->sum('n'),
                'dominant_category' => $dominant->category,
            ];
        })->values()->all();

        return [
            'mode' => 'clusters',
            'truncated' => false,
            'clusters' => $clusters,
        ];
    }
}
