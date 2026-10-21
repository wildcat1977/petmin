<?php

namespace App\Http\Controllers;

use App\Models\TrashBin;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 巡桶貼紙列印頁（管理員）：一張 A4 排 8 張，每張是打卡頁 /b/{官方編號} 的 QR Code。
 *   ?codes=a,b,c        指定桶（官方編號；市民新增的桶用 id）
 *   ?near=lat,lng&n=8   某點附近最近的 n 個清潔箱（預設示範位置）
 * QR 網址用目前請求的網域：本機印出本機網址、正式機印出正式網址。
 */
class BinStickerController extends Controller
{
    private const MAX = 24;

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $request->validate([
            'codes' => ['nullable', 'string', 'max:2000'],
            'near' => ['nullable', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'n' => ['nullable', 'integer', 'between:1,'.self::MAX],
        ]);

        if (! empty($validated['codes'])) {
            $codes = collect(explode(',', $validated['codes']))->map(fn ($c) => trim($c))->filter()->take(self::MAX);
            $bins = TrashBin::whereIn('external_id', $codes)
                ->orWhere(fn ($q) => $q->whereNull('external_id')->whereIn('id', $codes->filter(fn ($c) => ctype_digit($c))))
                ->get()
                ->sortBy(fn (TrashBin $b) => $codes->search($b->external_id ?? (string) $b->id))
                ->values();
        } else {
            $demo = config('petmin.find_bin.demo_point');
            [$lat, $lng] = isset($validated['near']) ? array_map('floatval', explode(',', $validated['near'])) : [$demo['lat'], $demo['lng']];
            $bbox = Geo::bbox($lat, $lng, 1500);
            $bins = TrashBin::query()
                ->where('kind', TrashBin::KIND_CLEAN_BOX)
                ->whereIn('status', [TrashBin::STATUS_ACTIVE, TrashBin::STATUS_UNVERIFIED])
                ->whereBetween('latitude', [$bbox['south'], $bbox['north']])
                ->whereBetween('longitude', [$bbox['west'], $bbox['east']])
                ->get()
                ->sortBy(fn (TrashBin $b) => Geo::distanceMeters($lat, $lng, (float) $b->latitude, (float) $b->longitude))
                ->take((int) ($validated['n'] ?? 8))
                ->values();
        }

        return view('pages.bin-stickers', [
            'stickers' => $bins->map(fn (TrashBin $b) => [
                'name' => $b->address ?: '清潔箱',
                'district' => $b->district,
                'code' => $b->external_id ?? (string) $b->id,
                'url' => url('/b/'.($b->external_id ?? $b->id)).'?via=qr',
            ]),
        ]);
    }
}
