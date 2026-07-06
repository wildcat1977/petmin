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
 * 災時開設狀態（is_open）以後台手動為準——同步只更新基本資料，不覆寫 is_open。
 *
 * ⚠ data.taipei dataset rid 待確認（Open Question）：未設定 TAIPEI_SHELTER_RESOURCE_ID
 * 前，此命令回報失敗並亮健康燈，不默默跳過。
 */
class SyncShelters extends Command
{
    protected $signature = 'civicshield:sync-shelters';

    protected $description = '同步 data.taipei 收容所清單（is_open 不覆寫）';

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

            $synced = 0;
            foreach ($rows as $row) {
                $mapped = $this->mapRow($row, $config['resource_id']);
                if (! $mapped) {
                    continue;
                }

                $shelter = Shelter::firstOrNew(['external_id' => $mapped['external_id']]);
                $shelter->fill($mapped);
                if (! $shelter->exists) {
                    $shelter->is_open = false; // 初始未開設；災時由後台切換
                }
                $shelter->save();
                $synced++;
            }

            $health->recordSuccess('shelters');
            $this->info("Shelters synced: {$synced}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('civicshield: shelter sync failed', ['error' => $e->getMessage()]);
            $health->recordFailure('shelters', $e->getMessage());
            $this->error("Shelter sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** 欄位名稱容錯對應（data.taipei 各版本欄名不一） */
    private function mapRow(array $row, string $resourceId): ?array
    {
        $pick = function (array $keys) use ($row) {
            foreach ($keys as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    return $row[$key];
                }
            }

            return null;
        };

        $name = $pick(['名稱', 'name', '收容所名稱', '避難收容處所名稱']);
        $lat = $pick(['緯度', 'lat', 'latitude', 'wgs84aY', 'ycord']);
        $lng = $pick(['經度', 'lng', 'longitude', 'wgs84aX', 'xcord']);
        if (! $name || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'external_id' => $resourceId.'_'.($row['_id'] ?? md5($name)),
            'name' => mb_substr($name, 0, 255),
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'address' => $pick(['地址', 'address', '地點位置']),
            'capacity' => is_numeric($pick(['容量', 'capacity', '收容人數'])) ? (int) $pick(['容量', 'capacity', '收容人數']) : null,
            'source' => 'data.taipei',
        ];
    }
}
