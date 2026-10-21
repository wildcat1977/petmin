<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FarSignal;
use App\Models\TrashBin;
use App\Support\DemandGrid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/far-signals：「好遠！」不需登入。
 * 位置先換成需求網格的格中心再存；同一 IP、同一格、同一天已按過就只回 accepted=false。
 */
class FarSignalController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'nearest_bin_id' => ['nullable', 'integer', Rule::exists(TrashBin::class, 'id')],
            'nearest_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'distance_mode' => ['required', Rule::in([FarSignal::MODE_WALKING, FarSignal::MODE_ESTIMATED])],
        ]);

        $grid = DemandGrid::fromConfig();
        [$ix, $iy] = $grid->cellAt((float) $validated['lat'], (float) $validated['lng']);
        $cell = $grid->id($ix, $iy);
        $center = $grid->center($ix, $iy);
        $ipHash = hash('sha256', (string) $request->ip().'|'.config('app.key'));
        $today = now('Asia/Taipei')->toDateString();

        $bin = isset($validated['nearest_bin_id']) ? TrashBin::find($validated['nearest_bin_id']) : null;

        $signal = FarSignal::firstOrCreate(
            ['ip_hash' => $ipHash, 'cell' => $cell, 'signal_date' => $today],
            [
                'user_id' => $request->user()?->id,
                'latitude' => $center['lat'],
                'longitude' => $center['lng'],
                'district' => $bin?->district,
                'nearest_bin_id' => $bin?->id,
                'nearest_distance_m' => $validated['nearest_distance_m'] ?? null,
                'distance_mode' => $validated['distance_mode'],
            ],
        );

        if (! $signal->wasRecentlyCreated) {
            return response()->json(['accepted' => false, 'message' => '今天已經收到你在這附近的「好遠！」了']);
        }

        return response()->json(['accepted' => true, 'message' => '收到！這裡需要一個垃圾桶'], 201);
    }
}
