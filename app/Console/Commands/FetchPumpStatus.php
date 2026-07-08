<?php

namespace App\Console\Commands;

use App\Models\PumpStation;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 抽水站即時運轉狀態（spec §11.5 P2，每 10 分鐘）。
 * 來源：臺北市水利處 HEO 開放資料（免認證），依 stn_id upsert。
 */
class FetchPumpStatus extends Command
{
    protected $signature = 'hazdar:fetch-pump-status';

    protected $description = '拉取臺北市水利處抽水站即時狀態並落地 pump_stations';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.pump');

        try {
            $response = Http::timeout(15)->get($config['url']);
            if (! $response->successful()) {
                throw new RuntimeException("HEO pump HTTP {$response->status()}");
            }

            $rows = $response->json();
            if (! is_array($rows) || $rows === []) {
                throw new RuntimeException('HEO pump 回應無資料');
            }

            $upserted = 0;
            foreach ($rows as $row) {
                if (empty($row['stn_id'])) {
                    continue;
                }
                PumpStation::updateOrCreate(
                    ['stn_id' => (string) $row['stn_id']],
                    [
                        'name' => mb_substr((string) ($row['stn_name'] ?? ''), 0, 255),
                        'latitude' => isset($row['lat']) ? (float) $row['lat'] : null,
                        'longitude' => isset($row['lon']) ? (float) $row['lon'] : null,
                        'pump_num' => (int) ($row['pumb_num'] ?? 0),
                        'gate_num' => (int) ($row['door_num'] ?? 0),
                        'pumb_status' => isset($row['pumb_status']) ? mb_substr((string) $row['pumb_status'], 0, 255) : null,
                        'door_status' => isset($row['door_status']) ? mb_substr((string) $row['door_status'], 0, 255) : null,
                        'inner_value' => is_numeric($row['inner_value'] ?? null) ? (float) $row['inner_value'] : null,
                        'outer_value' => is_numeric($row['outer_value'] ?? null) ? (float) $row['outer_value'] : null,
                        'max_level' => is_numeric($row['max_allowable_water_level'] ?? null) ? (float) $row['max_allowable_water_level'] : null,
                        'observed_at' => $this->parseObservedAt($row['obs_time'] ?? null),
                    ],
                );
                $upserted++;
            }

            $health->recordSuccess('pump');
            $this->info("Pump stations: {$upserted} upserted / ".PumpStation::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: pump fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('pump', $e->getMessage());
            $this->error("Pump fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function parseObservedAt(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'Asia/Taipei');
        } catch (Throwable) {
            return null;
        }
    }
}
