<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FarSignal;
use App\Models\TrashBin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * POST /api/far-signals：「好遠！」不需登入。
 * 同一 IP 冷卻內再按只回 accepted=false，不重複寫入（避免同一人狂按灌需求）。
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
            'distance_mode' => ['required', Rule::in([FarSignal::MODE_WALKING, FarSignal::MODE_STRAIGHT])],
        ]);

        $ipHash = hash('sha256', (string) $request->ip().'|'.config('app.key'));
        $cooldown = (int) config('petmin.find_bin.far_signal_cooldown_seconds');
        if (! Cache::add('far-signal:'.$ipHash, true, $cooldown)) {
            return response()->json(['accepted' => false, 'message' => '剛剛已經收到你的「好遠！」了']);
        }

        $bin = isset($validated['nearest_bin_id']) ? TrashBin::find($validated['nearest_bin_id']) : null;

        FarSignal::create([
            'user_id' => $request->user()?->id,
            'latitude' => $validated['lat'],
            'longitude' => $validated['lng'],
            'district' => $bin?->district,
            'nearest_bin_id' => $bin?->id,
            'nearest_distance_m' => $validated['nearest_distance_m'] ?? null,
            'distance_mode' => $validated['distance_mode'],
            'ip_hash' => $ipHash,
        ]);

        return response()->json(['accepted' => true, 'message' => '收到！這裡需要一個垃圾桶'], 201);
    }
}
