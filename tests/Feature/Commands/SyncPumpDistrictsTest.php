<?php

namespace Tests\Feature\Commands;

use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 抽水站行政區補值：依站名比對 data.taipei 靜態清冊（站名/行政區域），
 * 只更新既有 pump_stations（HEO 即時來源已建檔）的 district 欄位。
 */
class SyncPumpDistrictsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pump_station_district_is_set_by_name_match(): void
    {
        PumpStation::factory()->create(['name' => '建國', 'district' => null]);
        PumpStation::factory()->create(['name' => '無資料站', 'district' => null]);

        Http::fake(['data.taipei/*' => Http::response(['result' => ['results' => [
            ['站名' => '建國', '行政區域' => '中山區'],
            ['站名' => '長安', '行政區域' => '中山區'], // 清冊有但本地無此站，應被忽略
        ]]])]);

        $this->artisan('hazdar:sync-pump-districts')->assertSuccessful();

        $this->assertSame('中山區', PumpStation::where('name', '建國')->sole()->district);
        $this->assertNull(PumpStation::where('name', '無資料站')->sole()->district);
    }

    public function test_failure_does_not_throw(): void
    {
        Http::fake(['data.taipei/*' => Http::response('x', 500)]);

        $this->artisan('hazdar:sync-pump-districts')->assertFailed();
    }
}
