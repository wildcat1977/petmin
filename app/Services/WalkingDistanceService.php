<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 步行距離：呼叫 OSRM table API（foot profile），一次算「起點 → 多個終點」的步行公尺數。
 * 失敗（逾時、非 Ok、格式不對）一律回 null，由呼叫端退回直線距離，不讓找桶整個掛掉。
 */
class WalkingDistanceService
{
    /**
     * @param  array<int, array{lat: float, lng: float}>  $destinations
     * @return array<int, float|null>|null 與 $destinations 同順序；某個終點路網接不到時該格為 null；整批失敗回 null
     */
    public function distancesFrom(float $lat, float $lng, array $destinations): ?array
    {
        if ($destinations === []) {
            return [];
        }

        // 路網是選配：網址留空就不連（公益 OSRM 對單一 IP 有流量上限，正式機全部請求都出自同一台伺服器）
        $base = trim((string) config('petmin.find_bin.walking.osrm_url'));
        if ($base === '') {
            return null;
        }

        $coords = collect([['lat' => $lat, 'lng' => $lng], ...$destinations])
            ->map(fn (array $p) => sprintf('%.6f,%.6f', $p['lng'], $p['lat']))
            ->implode(';');
        $url = rtrim($base, '/').'/table/v1/foot/'.$coords;

        try {
            // 公益服務的使用規範要求識別來源
            $res = Http::withUserAgent('Petmin/1.0 (+'.config('app.url').')')
                ->timeout((int) config('petmin.find_bin.walking.timeout_seconds'))
                ->get($url, ['sources' => 0, 'annotations' => 'distance']);
        } catch (Throwable $e) {
            Log::warning('walking distance request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $row = $res->json('distances.0');
        if (! $res->ok() || $res->json('code') !== 'Ok' || ! is_array($row) || count($row) !== count($destinations) + 1) {
            Log::warning('walking distance bad response', ['status' => $res->status(), 'code' => $res->json('code')]);

            return null;
        }

        // 第 0 格是起點到自己
        return array_map(fn ($m) => is_numeric($m) ? (float) $m : null, array_slice($row, 1));
    }
}
