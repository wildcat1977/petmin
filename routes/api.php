<?php

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Public read-only API
 * All responses are JSON. No authentication required.
 *
 * Rate limit: 60 req/min per IP (via throttle middleware configured in bootstrap/app.php)
 */

/**
 * GET /api/v1/reports
 *
 * Returns visible reports (pending / approved; photos only exposed once approved) in JSON.
 *
 * Query parameters:
 *   from  – ISO 8601 date/datetime, e.g. 2026-01-01 or 2026-01-01T00:00:00  (inclusive)
 *   to    – ISO 8601 date/datetime                                            (inclusive, defaults to now)
 *   category – "flood" | "road_blocked" | "power_outage" | "structural_damage" (optional)
 *   per_page – integer 1-500, default 200
 *   page  – integer, default 1
 *
 * Response shape:
 * {
 *   "data": [
 *     {
 *       "id": 1,
 *       "category": "flood",
 *       "latitude": 25.034,
 *       "longitude": 121.565,
 *       "address": "台北市大安區...",
 *       "description": "...",
 *       "image_url": "https://...",
 *       "reported_at": "2026-04-01T10:23:00+08:00",
 *       "status": "approved"
 *     }
 *   ],
 *   "meta": { "current_page": 1, "last_page": 3, "per_page": 200, "total": 500 }
 * }
 */
Route::get('/v1/reports', function (Request $request) {
    $validated = $request->validate([
        'from' => ['nullable', 'date'],
        'to' => ['nullable', 'date'],
        'category' => ['nullable', 'string', 'in:'.implode(',', Report::CATEGORIES)],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        'page' => ['nullable', 'integer', 'min:1'],
    ]);

    $perPage = (int) ($validated['per_page'] ?? 200);

    $query = Report::query()
        ->whereIn('status', Report::VISIBLE_STATUSES)
        ->select(['id', 'category', 'latitude', 'longitude', 'address', 'description', 'image_path', 'status', 'created_at'])
        ->orderBy('created_at');

    if (! empty($validated['from'])) {
        $query->where('created_at', '>=', $validated['from']);
    }

    if (! empty($validated['to'])) {
        // include the full day if only a date (no time) is supplied
        $to = strlen($validated['to']) <= 10
            ? $validated['to'].' 23:59:59'
            : $validated['to'];
        $query->where('created_at', '<=', $to);
    }

    if (! empty($validated['category'])) {
        $query->where('category', $validated['category']);
    }

    $paginator = $query->paginate($perPage);

    $data = $paginator->getCollection()->map(function (Report $r) {
        return [
            'id' => $r->id,
            'category' => $r->category,
            'latitude' => (float) $r->latitude,
            'longitude' => (float) $r->longitude,
            'address' => $r->address ?: null,
            'description' => $r->description ?: null,
            // 照片僅在內容審核通過（approved）後公開；pending 點位上圖但照片不公開
            'image_url' => $r->publicImageUrl(),
            'reported_at' => $r->created_at
                ->setTimezone('Asia/Taipei')
                ->toIso8601String(),
            'status' => $r->status,
        ];
    });

    return response()->json([
        'data' => $data,
        'meta' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ],
    ]);
})->middleware('throttle:60,1')->name('api.v1.reports');
