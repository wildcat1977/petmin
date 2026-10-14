<?php

namespace Tests\Feature\Commands;

use App\Models\DemandProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 人流／遛狗 proxy 匯入：公車站牌、捷運站（含 OD 出站量權重）、狗活動區。 */
class ImportProxiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bus_stops_import_and_reimport_is_idempotent(): void
    {
        $file = base_path('tests/fixtures/bus_stops.csv');
        $this->artisan('proxies:import-bus-stops', ['--file' => $file])->assertSuccessful();
        $this->artisan('proxies:import-bus-stops', ['--file' => $file])->assertSuccessful();

        $this->assertSame(2, DemandProxy::where('kind', DemandProxy::KIND_BUS_STOP)->count());
        $this->assertSame('捷運西湖站', DemandProxy::where('external_id', '252')->sole()->name);
    }

    public function test_mrt_stations_without_od_get_weight_one(): void
    {
        $this->artisan('proxies:import-mrt', ['--file' => base_path('tests/fixtures/mrt_stations.csv')])
            ->expectsOutputToContain('權重一律 1')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(1.0, DemandProxy::where('name', '劍潭')->sole()->weight, 0.001);
    }

    /** 站點 CSV 自帶 weight（預先由 OD 換算好）時要照用，且輸出不可再說「一律 1」誤導操作的人 */
    public function test_mrt_stations_use_weight_column_when_present(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mrt');
        file_put_contents($file, "name,lng,lat,weight\n台北車站,121.5170,25.0478,1.000\n劍潭,121.5249,25.0847,0.250\n");

        $this->artisan('proxies:import-mrt', ['--file' => $file])
            ->expectsOutputToContain('權重取自 CSV weight 欄')
            ->doesntExpectOutputToContain('權重一律 1')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(0.25, DemandProxy::where('name', '劍潭')->sole()->weight, 0.001);
        unlink($file);
    }

    public function test_mrt_od_file_normalises_exit_volume_into_weight(): void
    {
        $this->artisan('proxies:import-mrt', [
            '--file' => base_path('tests/fixtures/mrt_stations.csv'),
            '--od' => base_path('tests/fixtures/mrt_od.csv'),
        ])->assertSuccessful();

        // 台北車站出站 400+200=600（最大 → 1.0）；劍潭出站 100 → 100/600
        $this->assertEqualsWithDelta(1.0, DemandProxy::where('name', '台北車站')->sole()->weight, 0.001);
        $this->assertEqualsWithDelta(0.167, DemandProxy::where('name', '劍潭')->sole()->weight, 0.001);
    }

    public function test_mrt_od_file_without_expected_columns_fails_loudly(): void
    {
        $this->artisan('proxies:import-mrt', [
            '--file' => base_path('tests/fixtures/mrt_stations.csv'),
            '--od' => base_path('tests/fixtures/bus_stops.csv'),
        ])->expectsOutputToContain('找不到「出站」或「人次」')->assertFailed();

        $this->assertSame(0, DemandProxy::count());
    }

    public function test_dog_parks_import_distinguishes_dog_park_from_park(): void
    {
        $this->artisan('proxies:import-dog-parks', ['--file' => base_path('tests/fixtures/dog_parks.json')])->assertSuccessful();

        $this->assertSame(DemandProxy::KIND_DOG_PARK, DemandProxy::where('name', '華山公園狗活動區')->sole()->kind);
        $this->assertSame(DemandProxy::KIND_PARK, DemandProxy::where('name', '大安森林公園')->sole()->kind);
        $this->assertTrue(DemandProxy::where('name', '華山公園狗活動區')->sole()->meta['approx']);
    }
}
