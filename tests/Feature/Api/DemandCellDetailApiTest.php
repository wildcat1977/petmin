<?php

namespace Tests\Feature\Api;

use App\Models\DemandProxy;
use App\Models\TrashBin;
use App\Services\DemandScoreService;
use App\Support\DemandGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 點需求色塊看明細：地圖上一塊紅，要能說出「為什麼紅」（哪個捷運站、幾個站牌、離桶多遠），
 * 評審與環保局才會信這張圖；被附近現存桶扣到 0 的格子也要說得出原因。
 */
class DemandCellDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private DemandGrid $grid;

    private int $ix;

    private int $iy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grid = DemandGrid::fromConfig();
        [$this->ix, $this->iy] = $this->grid->cellAt(25.0330, 121.5654);
    }

    /** @return array{latitude: float, longitude: float} 目標格中心往東 $east 公尺 */
    private function at(float $east = 0): array
    {
        $c = $this->grid->center($this->ix, $this->iy);

        return ['latitude' => $c['lat'], 'longitude' => $c['lng'] + $east / (DemandGrid::METERS_PER_DEGREE_LAT * cos(deg2rad($this->grid->originLat)))];
    }

    public function test_click_anywhere_in_a_cell_returns_its_labelled_breakdown_and_bounds(): void
    {
        DemandProxy::factory()->count(10)->create($this->at()); // 公車 5 → 上限 4（先建，資料順序在捷運前）
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_MRT_EXIT, 'name' => '市政府站出口2', 'weight' => 0.5, 'meta' => ['station' => '市政府站'], ...$this->at(150)]);
        TrashBin::factory()->create(['latitude' => $this->at()['latitude'] + 2000 / DemandGrid::METERS_PER_DEGREE_LAT, 'longitude' => $this->at()['longitude']]);
        app(DemandScoreService::class)->recompute();

        // 點的是格子的西南角附近，不是格中心：同一格就要拿到同一份明細
        $b = $this->grid->bounds($this->ix, $this->iy);
        $url = '/api/map/demand/at?lat='.($b['south'] + 0.0001).'&lng='.($b['west'] + 0.0001);
        $cell = $this->withHeader('Accept-Language', 'zh-TW')->getJson($url)->assertOk()->json('cell');

        $this->assertSame($this->grid->id($this->ix, $this->iy), $cell['cell']);
        $this->assertStringStartsWith('市政府站', $cell['title']); // 標題先用捷運站名：比公車站牌好認
        $this->assertEqualsWithDelta($b['south'], $cell['bounds']['south'], 1e-9);
        $this->assertEqualsWithDelta($b['east'], $cell['bounds']['east'], 1e-9);

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

    public function test_cell_zeroed_by_nearby_bins_still_explains_why(): void
    {
        DemandProxy::factory()->count(2)->create($this->at()); // 公車 1.0
        TrashBin::factory()->create(['address' => '仁愛路口', ...$this->at(150)]); // 隔壁格的桶也扣這格
        app(DemandScoreService::class)->recompute();

        $c = $this->grid->center($this->ix, $this->iy);
        $cell = $this->withHeader('Accept-Language', 'zh-TW')->getJson("/api/map/demand/at?lat={$c['lat']}&lng={$c['lng']}")->assertOk()->json('cell');

        $this->assertSame(0.0, (float) $cell['score']);
        $existing = collect($cell['items'])->firstWhere('key', 'existing_bin');
        $this->assertSame('現存清潔箱', $existing['label']);
        $this->assertStringContainsString('仁愛路口', $existing['note']);
        $this->assertLessThan(0, $existing['score']);
    }

    public function test_cell_without_any_signal_returns_null(): void
    {
        $this->getJson('/api/map/demand/at?lat=25.0330&lng=121.5654')->assertOk()->assertJsonPath('cell', null);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->getJson('/api/map/demand/at?lat=abc&lng=121.5654')->assertStatus(422);
        $this->getJson('/api/map/demand/at?lng=121.5654')->assertStatus(422);
    }

    /** 色塊 API 只傳格子編號＋分數，前端用 grid 參數自己畫：8,000 格也只有百來 KB */
    public function test_demand_map_payload_is_grid_indices_with_shared_grid_params(): void
    {
        config(['petmin.demand.bin_gap.max_multiplier' => 0.0]); // 只驗格式；測試庫沒有清潔箱，加成會滿額
        DemandProxy::factory()->count(8)->create($this->at());
        app(DemandScoreService::class)->recompute();

        $res = $this->getJson('/api/map/demand')->assertOk();

        $this->assertSame($this->grid->toArray(), $res->json('grid'));
        $cells = collect($res->json('cells'))->keyBy(fn ($c) => "{$c[0]}:{$c[1]}");
        $this->assertEqualsWithDelta(4.0, $cells[$this->grid->id($this->ix, $this->iy)][2], 0.01);
        $this->assertTrue($cells->every(fn ($c) => $c[2] > 0), '分數 0 的格子不下發');
    }
}
