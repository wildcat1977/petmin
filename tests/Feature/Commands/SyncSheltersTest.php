<?php

namespace Tests\Feature\Commands;

use App\Models\Shelter;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 收容所同步（spec §5）：data.taipei「臺北市可供避難收容處所一覽表」
 * 只有門牌地址、無座標 → upsert 建檔後以機構名稱 geocode 補座標；
 * is_open 不被同步覆寫（災時後台為準）。
 */
class SyncSheltersTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSources(bool $geocodeHits = true): void
    {
        config([
            'civicshield.sources.shelters.resource_id' => 'RID',
            'civicshield.sources.shelters.url' => 'https://data.taipei/api/v1/dataset/%s',
            'civicshield.sources.shelters.geocode_url' => 'https://nominatim.openstreetmap.org/search',
            'civicshield.sources.shelters.geocode_delay_us' => 0, // 測試不 sleep
        ]);

        Http::fake([
            'data.taipei/*' => Http::response([
                'result' => ['results' => [
                    [
                        '收容所編號' => 'SA100-0002', '名稱' => '臺北市立螢橋國民中學',
                        '縣市' => '臺北市', '鄉鎮' => '中正區', '門牌地址' => '汀州路三段四號',
                        '容納人數' => '52', '水災' => 'Y',
                    ],
                    [
                        '收容所編號' => 'SA100-0003', '名稱' => '臺北市立大學附設實驗國民小學',
                        '縣市' => '臺北市', '鄉鎮' => '中正區', '門牌地址' => '公園路29號',
                        '容納人數' => '157', '水災' => 'Y',
                    ],
                ]],
            ]),
            'nominatim.openstreetmap.org/*' => $geocodeHits
                ? Http::response([['lat' => '25.0186446', 'lon' => '121.5256942', 'display_name' => '螢橋國中']])
                : Http::response([]),
        ]);
    }

    public function test_shelters_upserted_and_geocoded_by_name(): void
    {
        $this->fakeSources();

        $this->artisan('civicshield:sync-shelters')->assertSuccessful();

        $this->assertSame(2, Shelter::count());
        $shelter = Shelter::where('external_id', 'RID_SA100-0002')->sole();
        $this->assertSame('臺北市立螢橋國民中學', $shelter->name);
        $this->assertSame('臺北市中正區汀州路三段四號', $shelter->address);
        $this->assertSame(52, $shelter->capacity);
        // 無座標 dataset → geocode 補上座標，可上圖
        $this->assertNotNull($shelter->latitude);
        $this->assertNotNull($shelter->geocoded_at);
        $this->assertSame(2, Shelter::mappable()->count());

        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('shelters')['status']);
    }

    public function test_sync_does_not_overwrite_manual_is_open(): void
    {
        $this->fakeSources();
        $this->artisan('civicshield:sync-shelters')->assertSuccessful();

        // 官方後台在災時手動開設
        $shelter = Shelter::first();
        $shelter->update(['is_open' => true]);

        // 再同步一次不得把 is_open 打回 false
        $this->artisan('civicshield:sync-shelters')->assertSuccessful();
        $this->assertTrue($shelter->fresh()->is_open);
        $this->assertSame(2, Shelter::count()); // upsert 不重複建檔
    }

    public function test_ungeocodable_shelter_stays_off_map_but_is_stored(): void
    {
        $this->fakeSources(geocodeHits: false);

        $this->artisan('civicshield:sync-shelters')->assertSuccessful();

        // geocode 失敗仍建檔（保留 name/address），只是不上圖，下輪重試
        $this->assertSame(2, Shelter::count());
        $this->assertSame(0, Shelter::mappable()->count());
        $this->assertNull(Shelter::first()->latitude);
    }

    public function test_missing_resource_id_fails_loudly(): void
    {
        config(['civicshield.sources.shelters.resource_id' => null]);

        $this->artisan('civicshield:sync-shelters')->assertFailed();
        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('shelters')['status']);
    }
}
