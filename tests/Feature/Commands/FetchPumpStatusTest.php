<?php

namespace Tests\Feature\Commands;

use App\Models\PumpStation;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchPumpStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_pump_stations_are_ingested_and_upserted(): void
    {
        // 注意：兩輪回應用同一個 fakeSequence（而非兩次 Http::fake()）——
        // Http::fake() 對同一 URL pattern 重複呼叫時，同一測試方法內先註冊的
        // fake 會先比對到而勝出（stubCallbacks 只會 merge append，不會覆蓋），
        // 第二次呼叫因此不會生效。fakeSequence 依序彈出回應才能正確模擬「重跑」。
        Http::fakeSequence('heopublic.gov.taipei/*')
            ->push([
                ['stn_id' => '211', 'stn_name' => '玉成', 'lon' => 121.5825, 'lat' => 25.05234,
                    'obs_time' => '2026-07-08 14:15:00', 'inner_value' => '0.02', 'outer_value' => '0.11',
                    'pumb_num' => 7, 'door_num' => 3, 'pumb_status' => '停止', 'door_status' => '閘門關閉',
                    'max_allowable_water_level' => 3.15],
                ['stn_id' => '213', 'stn_name' => '南京', 'lon' => 121.5722, 'lat' => 25.05069,
                    'obs_time' => '2026-07-08 14:00:00', 'inner_value' => '-0.03', 'outer_value' => '0.24',
                    'pumb_num' => 3, 'door_num' => 1, 'pumb_status' => '運轉中', 'door_status' => '閘門開啟',
                    'max_allowable_water_level' => 3.9],
            ])
            ->push([
                ['stn_id' => '211', 'stn_name' => '玉成', 'lon' => 121.5825, 'lat' => 25.05234,
                    'obs_time' => '2026-07-08 15:00:00', 'inner_value' => '0.5', 'outer_value' => '0.9',
                    'pumb_num' => 7, 'door_num' => 3, 'pumb_status' => '運轉中', 'door_status' => '閘門開啟',
                    'max_allowable_water_level' => 3.15],
            ]);

        $this->artisan('hazdar:fetch-pump-status')->assertSuccessful();

        $this->assertSame(2, PumpStation::count());
        $yucheng = PumpStation::where('stn_id', '211')->sole();
        $this->assertSame('玉成', $yucheng->name);
        $this->assertSame('停止', $yucheng->pumb_status);
        $this->assertSame('閘門關閉', $yucheng->door_status);
        $this->assertEqualsWithDelta(25.05234, $yucheng->latitude, 0.00001);
        $this->assertSame('2026-07-08 14:15:00', $yucheng->observed_at->timezone('Asia/Taipei')->format('Y-m-d H:i:s'));
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('pump')['status']);

        // 重跑不重複（依 stn_id upsert），且更新狀態
        $this->artisan('hazdar:fetch-pump-status')->assertSuccessful();
        $this->assertSame(2, PumpStation::count());
        $this->assertSame('運轉中', PumpStation::where('stn_id', '211')->sole()->pumb_status);
    }

    public function test_failure_degrades_health_without_throwing(): void
    {
        Http::fake(['heopublic.gov.taipei/*' => Http::response('oops', 503)]);
        $this->artisan('hazdar:fetch-pump-status')->assertFailed();

        $status = app(DataSourceHealth::class)->status('pump');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $status['status']);
        $this->assertNotNull($status['last_error']);
    }
}
