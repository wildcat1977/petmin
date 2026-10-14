<?php

namespace Tests\Feature\Api;

use App\Models\DemandProxy;
use App\Models\TrashBin;
use App\Services\DemandScoreService;
use App\Support\Geohash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 點熱力圖看明細：地圖上一塊紅，要能說出「為什麼紅」（哪個捷運站、幾個站牌、離桶多遠），
 * 評審與環保局才會信這張圖；被現存桶扣到 0 的格子也要說得出原因。
 */
class DemandCellDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 25.0330;

    private const LNG = 121.5654;

    private function cellCenter(): array
    {
        return Geohash::decodeCenter(substr(Geohash::encode(self::LAT, self::LNG, 7), 0, 6));
    }

    public function test_click_anywhere_in_a_cell_returns_its_labelled_breakdown_and_bounds(): void
    {
        DemandProxy::factory()->count(10)->create(['latitude' => self::LAT, 'longitude' => self::LNG]); // 公車 5 → 上限 4（先建，資料順序在捷運前）
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_MRT, 'name' => '市政府站', 'weight' => 0.5, 'latitude' => self::LAT, 'longitude' => self::LNG]);
        $center = $this->cellCenter();
        TrashBin::factory()->create(['latitude' => $center['lat'] + 2000 / 111195, 'longitude' => $center['lng']]);
        app(DemandScoreService::class)->recompute();

        // 點的是格中心，不是 proxy 所在的那個點：同一格就要拿到同一份明細
        $url = "/api/map/demand/at?lat={$center['lat']}&lng={$center['lng']}";
        $cell = $this->withHeader('Accept-Language', 'zh-TW')->getJson($url)->assertOk()->json('cell');

        $this->assertSame(substr(Geohash::encode(self::LAT, self::LNG, 7), 0, 6), $cell['geohash6']);
        $this->assertStringStartsWith('市政府站', $cell['title']); // 標題先用捷運站名：比公車站牌好認
        $this->assertLessThan($center['lat'], $cell['bounds']['south']);
        $this->assertGreaterThan($center['lat'], $cell['bounds']['north']);

        $items = collect($cell['items'])->keyBy('key');
        $this->assertSame('捷運', $items['mrt']['label']);
        $this->assertSame('公車站牌', $items['bus_stop']['label']);
        $this->assertSame(10, $items['bus_stop']['count']);
        $this->assertStringContainsString('已上限', $items['bus_stop']['note']);
        $this->assertSame('缺桶加成', $items['bin_gap']['label']);
        $this->assertStringContainsString('2,000', $items['bin_gap']['note']);
        $this->assertEqualsWithDelta($items->sum('score'), $cell['score'], 0.05);

        // 切到英文介面時明細也要跟著換語言
        $en = collect($this->withHeader('Accept-Language', 'en')->getJson($url)->json('cell.items'))->keyBy('key');
        $this->assertSame('MRT', $en['mrt']['label']);
    }

    public function test_cell_zeroed_by_existing_bins_still_explains_why(): void
    {
        DemandProxy::factory()->count(2)->create(['latitude' => self::LAT, 'longitude' => self::LNG]); // 公車 1.0
        TrashBin::factory()->create(['address' => '仁愛路口', 'latitude' => self::LAT, 'longitude' => self::LNG]);
        app(DemandScoreService::class)->recompute();

        $cell = $this->withHeader('Accept-Language', 'zh-TW')->getJson('/api/map/demand/at?lat='.self::LAT.'&lng='.self::LNG)->assertOk()->json('cell');

        $this->assertSame(0.0, (float) $cell['score']);
        $existing = collect($cell['items'])->firstWhere('key', 'existing_bin');
        $this->assertSame('現存清潔箱', $existing['label']);
        $this->assertLessThan(0, $existing['score']);
    }

    public function test_cell_without_any_signal_returns_null(): void
    {
        $this->getJson('/api/map/demand/at?lat='.self::LAT.'&lng='.self::LNG)->assertOk()->assertJsonPath('cell', null);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->getJson('/api/map/demand/at?lat=abc&lng='.self::LNG)->assertStatus(422);
        $this->getJson('/api/map/demand/at?lng='.self::LNG)->assertStatus(422);
    }
}
