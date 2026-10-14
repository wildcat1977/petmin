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

    private function mrtExitFiles(string $exits, string $stations): array
    {
        $e = tempnam(sys_get_temp_dir(), 'exits');
        $s = tempnam(sys_get_temp_dir(), 'stations');
        file_put_contents($e, "項次,出入口名稱,出入口編號,經度,緯度,是否為無障礙用\n".$exits);
        file_put_contents($s, "name,lng,lat,weight,exits\n".$stations);

        return ['--exits' => $e, '--stations' => $s];
    }

    /**
     * 捷運人流要從出口往外算（站內垃圾桶在閘門旁，出站往外走的人不會回頭丟）：
     * 一站的權重平分給各出口，大站不會因為出口多就被重複放大。
     */
    public function test_mrt_exits_split_station_weight_evenly_and_reimport_replaces(): void
    {
        $args = $this->mrtExitFiles(
            "1,台北車站M1,M1,121.518193,25.048232,否\n2,台北車站M2,M2,121.5175,25.0475,是\n3,劍潭站出口1,1,121.5249,25.0847,是\n",
            "台北車站,121.5177,25.0478,1.000,2\n劍潭站,121.5249,25.0847,0.250,1\n",
        );

        $this->artisan('proxies:import-mrt-exits', $args)->expectsOutputToContain('3 個出入口（2 站）')->assertSuccessful();
        $this->artisan('proxies:import-mrt-exits', $args)->assertSuccessful();

        $exits = DemandProxy::where('kind', DemandProxy::KIND_MRT_EXIT)->get()->keyBy('name');
        $this->assertCount(3, $exits);
        $this->assertEqualsWithDelta(0.5, $exits['台北車站M1']->weight, 0.001);
        $this->assertEqualsWithDelta(0.25, $exits['劍潭站出口1']->weight, 0.001);
        $this->assertSame('台北車站', $exits['台北車站M1']->meta['station']);
    }

    /** 出口對不到站點權重就大聲失敗：靜默給 1.0 等於跟台北車站一樣重 */
    public function test_mrt_exit_without_station_weight_fails_loudly(): void
    {
        $args = $this->mrtExitFiles("1,新站出口1,1,121.5,25.0,是\n", "台北車站,121.5177,25.0478,1.000,1\n");

        $this->artisan('proxies:import-mrt-exits', $args)->expectsOutputToContain('新站')->assertFailed();
        $this->assertSame(0, DemandProxy::where('kind', DemandProxy::KIND_MRT_EXIT)->count());
    }
}
