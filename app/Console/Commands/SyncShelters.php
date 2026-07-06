<?php

namespace App\Console\Commands;

use App\Models\Shelter;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 收容所清單同步（spec §5，daily）。
 * 來源：data.taipei「臺北市可供避難收容處所一覽表」（dataset 134683，約 401 筆）。
 *
 * 此 dataset 只有門牌地址、無經緯度 → 先 upsert 建檔（name/address/capacity），
 * 座標以門牌地址向 Nominatim geocode 逐批補齊（每輪上限 geocode_per_run，1 req/sec）。
 *
 * 災時開設狀態（is_open）以後台手動為準——同步只更新基本資料，不覆寫 is_open。
 */
class SyncShelters extends Command
{
    protected $signature = 'civicshield:sync-shelters {--limit= : 覆寫本輪 geocode 上限}';

    protected $description = '同步 data.taipei 收容所清單並 geocode 補座標（is_open 不覆寫）';

    /** 台北市概略邊界 [minLat, maxLat, minLng, maxLng]，用於約束與驗證 geocode 結果 */
    private const TAIPEI_BBOX = [24.95, 25.22, 121.45, 121.67];

    /** geocode 服務回傳限流（429）時中止本輪，下一輪 schedule 自然續跑 */
    private bool $rateLimited = false;

    public function handle(DataSourceHealth $health): int
    {
        $config = config('civicshield.sources.shelters');

        try {
            if (empty($config['resource_id'])) {
                throw new RuntimeException('TAIPEI_SHELTER_RESOURCE_ID 未設定，無法同步收容所');
            }

            $response = Http::timeout(10)->get(sprintf($config['url'], $config['resource_id']));
            if (! $response->successful()) {
                throw new RuntimeException("data.taipei HTTP {$response->status()}");
            }

            $rows = data_get($response->json(), 'result.results', []);
            if (empty($rows)) {
                throw new RuntimeException('data.taipei 回應無資料列');
            }

            $upserted = 0;
            foreach ($rows as $row) {
                $mapped = $this->mapRow($row, $config['resource_id']);
                if (! $mapped) {
                    continue;
                }

                $shelter = Shelter::firstOrNew(['external_id' => $mapped['external_id']]);
                $addressChanged = $shelter->address !== $mapped['address'];
                $shelter->fill($mapped);
                if (! $shelter->exists) {
                    $shelter->is_open = false; // 初始未開設；災時由後台切換
                }
                // 地址變動時清座標，下方 geocode 會重補
                if ($addressChanged && $shelter->exists) {
                    $shelter->latitude = null;
                    $shelter->longitude = null;
                    $shelter->geocoded_at = null;
                }
                $shelter->save();
                $upserted++;
            }

            $geocoded = $this->geocodeMissing($config, (int) ($this->option('limit') ?? $config['geocode_per_run']));

            $health->recordSuccess('shelters');
            $mappable = Shelter::mappable()->count();
            $this->info("Shelters synced: {$upserted} upserted, {$geocoded} geocoded, {$mappable} mappable / ".Shelter::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('civicshield: shelter sync failed', ['error' => $e->getMessage()]);
            $health->recordFailure('shelters', $e->getMessage());
            $this->error("Shelter sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * 對尚無座標的收容所 geocode（Nominatim，1 req/sec 政策）。
     * 單筆失敗不阻塞其餘；下一輪同步自然重試。
     */
    private function geocodeMissing(array $config, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $pending = Shelter::whereNull('latitude')
            ->where(fn ($q) => $q->whereNotNull('name')->orWhereNotNull('address'))
            ->limit($budget)
            ->get();

        $done = 0;
        foreach ($pending as $shelter) {
            $coords = $this->geocode($config['geocode_url'], $shelter);
            if ($this->rateLimited) {
                $this->warn('geocode 服務回傳限流，本輪提前結束，剩餘留待下一輪');
                break;
            }
            if ($coords) {
                $shelter->forceFill([
                    'latitude' => $coords[0],
                    'longitude' => $coords[1],
                    'geocoded_at' => now(),
                ])->save();
                $done++;
            }
            usleep($this->geocodeDelay()); // Nominatim 政策：最多 1 req/sec
        }

        return $done;
    }

    /**
     * 依序嘗試多個查詢字串（機構名稱最準，地址為後備）。
     * Nominatim 對台灣「三段四號」中文門牌很弱，但收容所多為學校/公共設施，
     * 以機構名稱查命中率高。以台北市 viewbox 約束並驗證結果落在市界內，
     * 避免同名地點誤定位到外縣市。
     *
     * @return array{0: float, 1: float}|null
     */
    private function geocode(string $url, Shelter $shelter): ?array
    {
        // 名稱已含「臺北市」時不再重複附加，避免干擾比對
        $name = (string) $shelter->name;
        $candidates = array_values(array_unique(array_filter([
            $name,
            str_contains($name, '臺北市') || str_contains($name, '台北市') ? null : $name.' 臺北市',
            $shelter->address,
        ])));

        [$minLat, $maxLat, $minLng, $maxLng] = self::TAIPEI_BBOX;

        foreach ($candidates as $i => $query) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'CivicShield/1.0 (disaster reporting; contact admin)'])
                    ->get($url, [
                        'q' => $query,
                        'format' => 'json',
                        'countrycodes' => 'tw',
                        // 約束在台北市 viewbox 內（x1,y1,x2,y2 = 左上,右下 的經緯）
                        'viewbox' => "{$minLng},{$maxLat},{$maxLng},{$minLat}",
                        'bounded' => 1,
                        'limit' => 1,
                    ]);

                if ($response->status() === 429) {
                    $this->rateLimited = true;

                    return null;
                }

                $hit = data_get($response->json(), '0');
                if ($hit && isset($hit['lat'], $hit['lon'])) {
                    $lat = (float) $hit['lat'];
                    $lng = (float) $hit['lon'];
                    // 二次驗證：務必落在台北市界內才採用（擋掉誤定位）
                    if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) {
                        return [$lat, $lng];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('civicshield: shelter geocode failed', [
                    'shelter' => $shelter->name,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }

            // 同一收容所多次查詢之間也要遵守 1 req/sec
            if ($i < count($candidates) - 1) {
                usleep($this->geocodeDelay());
            }
        }

        return null;
    }

    private function geocodeDelay(): int
    {
        return (int) config('civicshield.sources.shelters.geocode_delay_us', 1_100_000);
    }

    /**
     * data.taipei 收容所欄位對應。dataset 無座標，座標留待 geocode。
     *
     * @return array{external_id: string, name: string, address: string, capacity: ?int, source: string}|null
     */
    private function mapRow(array $row, string $resourceId): ?array
    {
        $number = trim((string) ($row['收容所編號'] ?? ''));
        $name = trim((string) ($row['名稱'] ?? ''));
        if ($name === '') {
            return null;
        }

        // 組完整地址供 geocode：縣市 + 鄉鎮 + 門牌地址
        $address = trim(implode('', array_filter([
            $row['縣市'] ?? '',
            $row['鄉鎮'] ?? '',
            $row['門牌地址'] ?? '',
        ])));

        $capacity = $row['容納人數'] ?? null;

        return [
            'external_id' => $resourceId.'_'.($number !== '' ? $number : md5($name)),
            'name' => mb_substr($name, 0, 255),
            'address' => $address !== '' ? $address : null,
            'capacity' => is_numeric($capacity) ? (int) $capacity : null,
            'source' => 'data.taipei',
        ];
    }
}
