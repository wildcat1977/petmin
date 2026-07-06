<?php

namespace App\Console\Commands;

use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * CWA 測站即時觀測拉取（spec §5，每 5 分鐘）：
 * 溫度／時雨量／風速／能見度 → weather_observations（24H 趨勢）+ Redis 最新值。
 *
 * ⚠ CWA 開放資料平台欄位常改版，解析採防禦式 data_get + -99 缺測值過濾。
 */
class FetchCwaObservations extends Command
{
    protected $signature = 'hazdar:fetch-cwa-observations';

    protected $description = '拉取 CWA 測站即時觀測並落地 weather_observations + Redis 最新值';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.cwa_observations');

        try {
            if (empty($config['api_key'])) {
                throw new RuntimeException('CWA_API_KEY 未設定（opendata.cwa.gov.tw 授權碼制）');
            }

            $response = Http::timeout(10)->get($config['url'], [
                'Authorization' => $config['api_key'],
                'StationId' => $config['station_id'],
            ]);
            if (! $response->successful()) {
                throw new RuntimeException("CWA HTTP {$response->status()}");
            }

            $station = data_get($response->json(), 'records.Station.0');
            if (! $station) {
                throw new RuntimeException('CWA 回應無測站資料（records.Station 為空，確認 dataset 與 StationId）');
            }

            $observedAt = data_get($station, 'ObsTime.DateTime');
            if (! $observedAt) {
                throw new RuntimeException('CWA 回應缺 ObsTime.DateTime');
            }

            $we = data_get($station, 'WeatherElement', []);
            $values = [
                'temp' => $this->numeric(data_get($we, 'AirTemperature')),
                'rain_1h' => $this->numeric(data_get($we, 'Now.Precipitation')),
                'wind_speed' => $this->numeric(data_get($we, 'WindSpeed')),
                'visibility' => $this->numeric(data_get($we, 'VisibilityDescription')),
            ];

            WeatherObservation::updateOrCreate(
                [
                    'station_id' => $config['station_id'],
                    'observed_at' => Carbon::parse($observedAt),
                ],
                [...$values, 'raw' => $station],
            );

            Cache::forever('hazdar:cwa:latest', [
                ...$values,
                'station_id' => $config['station_id'],
                'observed_at' => Carbon::parse($observedAt)->toIso8601String(),
            ]);

            $health->recordSuccess('cwa_observations');
            $this->info("CWA observation stored ({$observedAt})");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: CWA fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('cwa_observations', $e->getMessage());
            $this->error("CWA fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** CWA 以 -99 系列值表示缺測；文字欄（如能見度 ">10"）萃取數字 */
    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/-?\d+(\.\d+)?/', $value, $m)) {
            $value = $m[0];
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number <= -90.0 ? null : $number;
    }
}
