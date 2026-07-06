<?php

namespace Tests\Feature\Commands;

use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CWA 觀測拉取（spec §5）：落地 weather_observations + Redis 最新值，
 * -99 缺測值轉 null，金鑰未設或 API 失敗都必須亮紅燈（驗收 §1-5）。
 */
class FetchCwaObservationsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCwa(array $weatherElement): void
    {
        config(['hazdar.sources.cwa_observations.api_key' => 'test-key']);
        Http::fake([
            'opendata.cwa.gov.tw/*' => Http::response([
                'records' => [
                    'Station' => [[
                        'StationId' => '466920',
                        'ObsTime' => ['DateTime' => '2026-07-06T14:00:00+08:00'],
                        'WeatherElement' => $weatherElement,
                    ]],
                ],
            ]),
        ]);
    }

    public function test_observation_is_stored_and_latest_cached(): void
    {
        $this->fakeCwa([
            'AirTemperature' => '27.3',
            'WindSpeed' => '44.0',
            'VisibilityDescription' => '>10',
            'Now' => ['Precipitation' => '78.0'],
        ]);

        $this->artisan('hazdar:fetch-cwa-observations')->assertSuccessful();

        $obs = WeatherObservation::sole();
        $this->assertSame(27.3, $obs->temp);
        $this->assertSame(78.0, $obs->rain_1h);
        $this->assertSame(44.0, $obs->wind_speed);
        // 能見度文字欄 ">10" 萃取數字
        $this->assertSame(10.0, $obs->visibility);

        $latest = Cache::get('hazdar:cwa:latest');
        $this->assertSame(27.3, $latest['temp']);
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('cwa_observations')['status']);
    }

    public function test_missing_value_sentinel_becomes_null(): void
    {
        $this->fakeCwa([
            'AirTemperature' => '-99',
            'WindSpeed' => '-99.0',
            'Now' => ['Precipitation' => '-99.0'],
        ]);

        $this->artisan('hazdar:fetch-cwa-observations')->assertSuccessful();

        $obs = WeatherObservation::sole();
        // CWA 以 -99 表缺測；存 null 才不會污染 24H 趨勢圖
        $this->assertNull($obs->temp);
        $this->assertNull($obs->rain_1h);
        $this->assertNull($obs->wind_speed);
    }

    public function test_rerun_upserts_same_observation_time(): void
    {
        $this->fakeCwa(['AirTemperature' => '27.3']);

        $this->artisan('hazdar:fetch-cwa-observations')->assertSuccessful();
        $this->artisan('hazdar:fetch-cwa-observations')->assertSuccessful();

        // unique(station_id, observed_at)：同一觀測時間不重複入庫
        $this->assertSame(1, WeatherObservation::count());
    }

    public function test_missing_api_key_fails_loudly(): void
    {
        config(['hazdar.sources.cwa_observations.api_key' => null]);

        $this->artisan('hazdar:fetch-cwa-observations')->assertFailed();

        $status = app(DataSourceHealth::class)->status('cwa_observations');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $status['status']);
        $this->assertStringContainsString('CWA_API_KEY', $status['last_error']['message']);
    }

    public function test_http_failure_degrades_health(): void
    {
        config(['hazdar.sources.cwa_observations.api_key' => 'test-key']);
        Http::fake(['opendata.cwa.gov.tw/*' => Http::response('', 500)]);

        $this->artisan('hazdar:fetch-cwa-observations')->assertFailed();

        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('cwa_observations')['status']);
    }
}
