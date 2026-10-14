<?php

namespace Tests\Feature\Services;

use App\Models\DemandCell;
use App\Models\DemandProxy;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use App\Services\DemandScoreService;
use App\Support\DemandGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 需求分數是提案的核心產出（候選復設點）。每格看「格中心半徑內」的訊號，而不是訊號落在哪一格：
 * 隔壁馬路的公車站、桶不能因為格線不同就不算。另外：只算已核准回報、佐證加倍、單一來源有上限、
 * 捷運從出口往外算（站內桶在閘門旁）、狗便袋箱算遛狗需求、離最近清潔箱越遠需求加成、家戶垃圾另算撤除風險。
 */
class DemandScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 25.0330;

    private const LNG = 121.5654;

    private DemandGrid $grid;

    private int $ix;

    private int $iy;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'petmin.demand.grid' => ['size_m' => 150, 'origin_lat' => 25.05, 'origin_lng' => 121.5],
            'petmin.demand.radius_m' => 300,
            'petmin.demand.mrt_exit' => ['inner_m' => 100, 'outer_m' => 500],
            'petmin.demand.weights' => [
                'mrt' => 8.0, 'bus_stop' => 0.5, 'dog_bag_box' => 2.0, 'dog_park' => 3.0, 'park' => 1.5, 'petition' => 1.0,
                'report_need_bin' => 2.0, 'report_full' => 1.0, 'corroborated_multiplier' => 2.0, 'existing_bin_penalty' => 2.5,
            ],
            'petmin.demand.caps' => ['bus_stop' => 4.0, 'mrt' => 10.0],
            // 測試庫裡通常沒有清潔箱＝離桶無限遠；預設關掉缺口加成，讓其他測試只驗基礎公式
            'petmin.demand.bin_gap' => ['start_m' => 300, 'full_m' => 1000, 'max_multiplier' => 0.0],
            'petmin.demand.candidate_spacing_m' => 300,
            'petmin.demand.dog_bag_box_site_m' => 150,
        ]);
        $this->grid = DemandGrid::fromConfig();
        [$this->ix, $this->iy] = $this->grid->cellAt(self::LAT, self::LNG);
    }

    /** 從目標格中心往東 $east、往北 $north 公尺的經緯度 @return array{latitude: float, longitude: float} */
    private function at(float $east = 0, float $north = 0): array
    {
        $c = $this->grid->center($this->ix, $this->iy);

        return [
            'latitude' => $c['lat'] + $north / DemandGrid::METERS_PER_DEGREE_LAT,
            'longitude' => $c['lng'] + $east / (DemandGrid::METERS_PER_DEGREE_LAT * cos(deg2rad(25.05))),
        ];
    }

    private function cell(int $dx = 0, int $dy = 0): ?DemandCell
    {
        return DemandCell::where('cell', $this->grid->id($this->ix + $dx, $this->iy + $dy))->first();
    }

    private function recompute(): void
    {
        app(DemandScoreService::class)->recompute();
    }

    /** 半徑內線性衰減：隔壁格（150m 外）的站牌照樣算進來，300m 外才不算 */
    public function test_signals_count_across_cell_boundaries_with_linear_decay(): void
    {
        DemandProxy::factory()->create($this->at(150, 0));   // 0.5 × (1 − 150/300) = 0.25
        DemandProxy::factory()->create($this->at(0, -75));   // 0.5 × (1 − 75/300) = 0.375
        DemandProxy::factory()->create($this->at(310, 0));   // 半徑外

        $this->recompute();

        $this->assertEqualsWithDelta(0.625, $this->cell()->score, 0.01);
        $this->assertSame(2, $this->cell()->breakdown['bus_stop']['count']);
    }

    /** 隔壁馬路就有桶：即使落在另一格，也要扣這格的分 */
    public function test_existing_bin_nearby_subtracts_even_across_cell_boundary(): void
    {
        DemandProxy::factory()->count(8)->create($this->at());                // 公車 4.0（上限）
        TrashBin::factory()->create(['address' => '隔壁路口', ...$this->at(100, 0)]); // 2.5 × (1 − 100/300) ≈ 1.667

        $this->recompute();

        $this->assertEqualsWithDelta(4.0 - 2.5 * 2 / 3, $this->cell()->score, 0.01);
        $this->assertSame(['隔壁路口'], $this->cell()->breakdown['existing_bin']['samples']);
    }

    /**
     * 捷運出口甜甜圈：站內垃圾桶在閘門旁，剛出站的人可以先丟；需求出現在出站往外走的路上，
     * 出口正上方不加權、100m 處最高、500m 外淡掉。站名當證據樣本（比出口編號好認）。
     */
    public function test_mrt_exit_demand_is_a_donut_around_the_exit(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_MRT_EXIT, 'name' => '市政府站出口2', 'weight' => 0.5,
            'meta' => ['station' => '市政府站'], ...$this->at()]);

        $this->recompute();

        $this->assertNull($this->cell(), '出口正上方：0 公尺不加權');
        // 隔壁格中心 150m：8 × 0.5 × (1 − (150 − 100) / 400) = 3.5
        $this->assertEqualsWithDelta(3.5, $this->cell(1, 0)->score, 0.01);
        $this->assertSame(['市政府站'], $this->cell(1, 0)->breakdown['mrt']['samples']);
        // 450m：8 × 0.5 × (1 − 350/400) = 0.5
        $this->assertEqualsWithDelta(0.5, $this->cell(3, 0)->score, 0.01);
    }

    /** 站點列（KIND_MRT）只是出口匯入的權重來源，不能跟出口重複計分 */
    public function test_station_rows_are_not_scored_alongside_exits(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_MRT, 'weight' => 1.0, ...$this->at(150, 0)]);

        $this->recompute();

        $this->assertNull($this->cell());
    }

    public function test_single_source_is_capped_per_cell(): void
    {
        DemandProxy::factory()->count(20)->create($this->at()); // 20 × 0.5 = 10，上限 4

        $this->recompute();

        $this->assertEqualsWithDelta(4.0, $this->cell()->score, 0.01);
        $this->assertTrue($this->cell()->breakdown['bus_stop']['capped']);
    }

    /** 狗便袋箱是遛狗族聚集的證據（提案公式「狗便袋箱 ×2」）；不見了的袋箱不再代表有人在這裡遛狗 */
    public function test_active_dog_bag_boxes_add_dog_walking_demand(): void
    {
        TrashBin::factory()->dogBagBox()->create($this->at());
        TrashBin::factory()->dogBagBox()->missing()->create($this->at());

        $this->recompute();

        $this->assertEqualsWithDelta(2.0, $this->cell()->score, 0.01);
        $this->assertSame(1, $this->cell()->breakdown['dog_bag_box']['count']);
    }

    /**
     * 同一個公園常裝一整排袋箱（迎風狗運動公園 01～06）：那是「一個遛狗地點」，不是六倍的遛狗需求。
     * site_m 內的袋箱併成一處只算一次，否則袋箱裝得多的公園分數會被灌高、擠掉其他缺桶地點。
     */
    public function test_nearby_dog_bag_boxes_count_as_one_dog_walking_site(): void
    {
        foreach ([[0, 0], [40, 0], [80, 0], [0, 40], [40, 40], [80, 40]] as [$east, $north]) {
            TrashBin::factory()->dogBagBox()->create(['address' => "迎風狗運動公園{$east}{$north}", ...$this->at($east, $north)]);
        }
        TrashBin::factory()->dogBagBox()->create($this->at(0, 1000)); // 1km 外另一處

        $this->recompute();

        $bag = $this->cell()->breakdown['dog_bag_box'];
        $this->assertSame(1, $bag['count']);
        $this->assertLessThanOrEqual(2.0, $bag['score']);
        $this->assertStringContainsString('等 6 個袋箱', $bag['samples'][0]);
        $this->assertNotNull($this->cell(0, 7)); // 1km 外那一處自己點亮
    }

    public function test_only_approved_unresolved_reports_count_and_corroborated_count_double(): void
    {
        Report::factory()->create(['category' => Report::CATEGORY_NEED_BIN, 'status' => Report::STATUS_PENDING, ...$this->at()]);
        Report::factory()->approved()->create(['category' => Report::CATEGORY_NEED_BIN, 'resolved_at' => now(), ...$this->at()]);
        Report::factory()->approved()->create(['category' => Report::CATEGORY_FULL, 'verification_level' => Report::VERIFICATION_CORROBORATED, ...$this->at()]);

        $this->recompute();

        $this->assertEqualsWithDelta(2.0, $this->cell()->score, 0.01); // 1.0 × 佐證 2
        $this->assertArrayNotHasKey('report_need_bin', $this->cell()->breakdown);
    }

    public function test_dumping_reports_feed_removal_risk_not_demand(): void
    {
        Report::factory()->approved()->create(['category' => Report::CATEGORY_DUMPING, ...$this->at()]);

        $this->recompute();

        $this->assertEqualsWithDelta(0.0, $this->cell()->score, 0.01);
        $this->assertEqualsWithDelta(1.0, $this->cell()->dumping_score, 0.01);
        $this->assertSame($this->cell()->cell, app(DemandScoreService::class)->dumpingRisk()->first()->cell);
    }

    /** 人多又離桶 1 公里以上：需求翻倍。只看附近扣分的話，沒桶的格子跟有人顧的格子分數一樣，候選點排不出來 */
    public function test_demand_doubles_when_nearest_clean_box_is_beyond_full_gap_distance(): void
    {
        config(['petmin.demand.bin_gap.max_multiplier' => 1.0]);
        DemandProxy::factory()->count(8)->create($this->at());
        TrashBin::factory()->create($this->at(0, 2000));

        $this->recompute();

        $this->assertEqualsWithDelta(8.0, $this->cell()->score, 0.01);
        $this->assertEqualsWithDelta(2000, $this->cell()->breakdown['bin_gap']['nearest_m'], 5);
    }

    /** 加成在 300m→1000m 之間線性增加：650m ≈ 一半 */
    public function test_gap_bonus_scales_linearly_between_start_and_full_distance(): void
    {
        config(['petmin.demand.bin_gap.max_multiplier' => 1.0]);
        DemandProxy::factory()->count(8)->create($this->at());
        TrashBin::factory()->create($this->at(0, 650));

        $this->recompute();

        $this->assertEqualsWithDelta(6.0, $this->cell()->score, 0.05);
    }

    /** 加成是乘在需求訊號上：山區零星一個站牌離桶再遠也不該擠上候選榜 */
    public function test_gap_bonus_multiplies_signal_so_weak_cells_stay_weak(): void
    {
        config(['petmin.demand.bin_gap.max_multiplier' => 1.0]);
        DemandProxy::factory()->create($this->at());
        TrashBin::factory()->create($this->at(0, 5000));

        $this->recompute();

        $this->assertEqualsWithDelta(1.0, $this->cell()->score, 0.01);
    }

    /** 候選清單：150m 格的同一熱點會佔好幾格，300m 內只留最高的一格，前 N 名才會是 N 個不同地點 */
    public function test_top_candidates_keep_one_cell_per_hotspot(): void
    {
        DemandProxy::factory()->count(8)->create($this->at());
        TrashBin::factory()->dogBagBox()->create($this->at()); // 這個熱點較高（6.0），排第一
        DemandProxy::factory()->count(8)->create($this->at(2100, 0)); // 2km 外另一熱點（落在格中心，4.0）

        $this->recompute();

        $top = app(DemandScoreService::class)->topCandidates(10);
        $this->assertCount(2, $top);
        $this->assertSame($this->cell()->cell, $top->first()->cell);
    }

    public function test_recompute_replaces_previous_cells_and_command_reports_count(): void
    {
        DemandProxy::factory()->create($this->at());
        $this->recompute();
        DemandProxy::query()->delete();
        User::factory()->create();

        $this->artisan('demand:recompute')->expectsOutputToContain('0 格')->assertSuccessful();

        $this->assertSame(0, DemandCell::count());
    }
}
