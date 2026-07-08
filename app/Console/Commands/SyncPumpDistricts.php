<?php

namespace App\Console\Commands;

use App\Models\PumpStation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 抽水站行政區補值（/info 頁面依行政區分組摺疊用）。
 * 來源：data.taipei 抽水站靜態清冊（站名 + 行政區域），依站名比對既有 pump_stations（HEO 即時來源）。
 * 靜態清冊非即時狀態，不記入 DataSourceHealth；單純 log 失敗，不拋出以免影響排程其他任務。
 */
class SyncPumpDistricts extends Command
{
    protected $signature = 'hazdar:sync-pump-districts';

    protected $description = '依站名比對 data.taipei 抽水站清冊，補齊 pump_stations.district';

    public function handle(): int
    {
        $config = config('hazdar.sources.pump');

        try {
            if (empty($config['inventory_resource_id'])) {
                throw new RuntimeException('TAIPEI_PUMP_INVENTORY_RESOURCE_ID 未設定');
            }

            $response = Http::timeout(15)->get(sprintf($config['inventory_url'], $config['inventory_resource_id']));
            if (! $response->successful()) {
                throw new RuntimeException("data.taipei HTTP {$response->status()}");
            }

            $rows = data_get($response->json(), 'result.results', []);
            if (empty($rows)) {
                throw new RuntimeException('data.taipei 回應無資料列');
            }

            $districtByName = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['站名'] ?? ''));
                $district = trim((string) ($row['行政區域'] ?? ''));
                if ($name !== '' && $district !== '') {
                    $districtByName[$name] = $district;
                }
            }

            $updated = 0;
            foreach ($districtByName as $name => $district) {
                $updated += PumpStation::where('name', $name)->update(['district' => $district]);
            }

            $this->info("Pump districts synced: {$updated} stations updated / ".PumpStation::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: pump district sync failed', ['error' => $e->getMessage()]);
            $this->error("Pump district sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
