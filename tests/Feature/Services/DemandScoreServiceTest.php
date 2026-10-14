<?php

namespace Tests\Feature\Services;

use App\Models\DemandCell;
use App\Models\DemandProxy;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use App\Services\DemandScoreService;
use App\Support\Geohash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 需求分數是提案的核心產出（候選復設點）。它必須：
 * 只算已核准回報、佐證回報加倍、現存桶扣分、單一來源有上限、家戶垃圾另算撤除風險、
 * 狗便袋箱算遛狗需求、離最近清潔箱越遠需求加成（人多又沒桶的地方才該排前面）。
 */
class DemandScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 25.0330;

    private const LNG = 121.5654;

    private function cell(): ?DemandCell
    {
        return DemandCell::where('geohash6', substr(Geohash::encode(self::LAT, self::LNG, 7), 0, 6))->first();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['petmin.demand.weights' => [
            'mrt' => 3.0, 'bus_stop' => 0.5, 'dog_bag_box' => 2.0, 'dog_park' => 3.0, 'park' => 1.5, 'petition' => 1.0,
            'report_need_bin' => 2.0, 'report_full' => 1.0, 'corroborated_multiplier' => 2.0, 'existing_bin_penalty' => 2.5,
        ], 'petmin.demand.caps' => ['bus_stop' => 4.0, 'mrt' => 6.0],
            // 測試庫裡通常沒有清潔箱＝離桶無限遠；預設關掉缺口加成，讓其他測試只驗基礎公式
            'petmin.demand.bin_gap' => ['start_m' => 300, 'full_m' => 1000, 'max_multiplier' => 0.0]]);
    }

    private function enableBinGap(): void
    {
        config(['petmin.demand.bin_gap.max_multiplier' => 1.0]);
    }

    /** 從格中心往北 $meters 公尺的位置放一個清潔箱 */
    private function cleanBoxNorthOfCellCenter(float $meters): void
    {
        $center = Geohash::decodeCenter(substr(Geohash::encode(self::LAT, self::LNG, 7), 0, 6));
        TrashBin::factory()->create(['latitude' => $center['lat'] + $meters / 111195, 'longitude' => $center['lng']]);
    }

    public function test_proxies_and_reports_add_up_and_existing_bins_subtract(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_DOG_PARK, 'latitude' => self::LAT, 'longitude' => self::LNG]);
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_MRT, 'weight' => 0.5, 'latitude' => self::LAT, 'longitude' => self::LNG]);
        Report::factory()->approved()->create(['category' => Report::CATEGORY_NEED_BIN, 'latitude' => self::LAT, 'longitude' => self::LNG]);
        TrashBin::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        // 3.0（狗公園）+ 3.0×0.5（捷運）+ 2.0（需要桶）− 2.5（現存桶）= 4.0
        $this->assertEqualsWithDelta(4.0, $this->cell()->score, 0.01);
        $this->assertSame(1, $this->cell()->breakdown['existing_bin']['count']);
    }

    /** 狗便袋箱是遛狗族聚集的證據（提案公式「狗便袋箱 ×2」）；不見了的袋箱不再代表有人在這裡遛狗 */
    public function test_active_dog_bag_boxes_add_dog_walking_demand(): void
    {
        TrashBin::factory()->dogBagBox()->create(['latitude' => self::LAT, 'longitude' => self::LNG]);
        TrashBin::factory()->dogBagBox()->missing()->create(['latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(2.0, $this->cell()->score, 0.01);
        $this->assertSame(1, $this->cell()->breakdown['dog_bag_box']['count']);
    }

    /** 人多又離桶 1 公里以上：需求翻倍。只看同格扣分的話，沒桶的格子跟有人顧的格子分數一樣，候選點排不出來 */
    public function test_demand_doubles_when_nearest_clean_box_is_beyond_full_gap_distance(): void
    {
        $this->enableBinGap();
        DemandProxy::factory()->count(8)->create(['latitude' => self::LAT, 'longitude' => self::LNG]); // 公車 4.0
        $this->cleanBoxNorthOfCellCenter(2000);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(8.0, $this->cell()->score, 0.01);
        $this->assertEqualsWithDelta(2000, $this->cell()->breakdown['bin_gap']['nearest_m'], 5);
    }

    /** 加成在 300m→1000m 之間線性增加：650m ≈ 一半 */
    public function test_gap_bonus_scales_linearly_between_start_and_full_distance(): void
    {
        $this->enableBinGap();
        DemandProxy::factory()->count(8)->create(['latitude' => self::LAT, 'longitude' => self::LNG]);
        $this->cleanBoxNorthOfCellCenter(650);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(6.0, $this->cell()->score, 0.05);
    }

    /** 加成是乘在需求訊號上：山區零星一個站牌離桶再遠也不該擠上候選榜 */
    public function test_gap_bonus_multiplies_signal_so_weak_cells_stay_weak(): void
    {
        $this->enableBinGap();
        DemandProxy::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG]); // 公車 0.5
        $this->cleanBoxNorthOfCellCenter(5000);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(1.0, $this->cell()->score, 0.01);
    }

    /** 300m 內就有桶：沒有缺口，不加成（只有同格現存桶扣分） */
    public function test_no_gap_bonus_when_a_clean_box_is_within_start_distance(): void
    {
        $this->enableBinGap();
        DemandProxy::factory()->count(8)->create(['latitude' => self::LAT, 'longitude' => self::LNG]);
        $this->cleanBoxNorthOfCellCenter(0); // 距離從格中心量（LAT/LNG 本身離格中心約 450m）

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(1.5, $this->cell()->score, 0.01); // 4.0 − 2.5
        $this->assertArrayNotHasKey('bin_gap', $this->cell()->breakdown);
    }

    public function test_pending_and_resolved_reports_do_not_count(): void
    {
        Report::factory()->create(['category' => Report::CATEGORY_NEED_BIN, 'status' => Report::STATUS_PENDING, 'latitude' => self::LAT, 'longitude' => self::LNG]);
        Report::factory()->approved()->create(['category' => Report::CATEGORY_NEED_BIN, 'resolved_at' => now(), 'latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        $this->assertNull($this->cell());
    }

    public function test_corroborated_reports_count_double(): void
    {
        Report::factory()->approved()->create(['category' => Report::CATEGORY_FULL, 'verification_level' => Report::VERIFICATION_CORROBORATED, 'latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(2.0, $this->cell()->score, 0.01);
    }

    public function test_single_source_is_capped_per_cell(): void
    {
        // 20 個公車站牌 × 0.5 = 10，上限 4
        DemandProxy::factory()->count(20)->create(['latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(4.0, $this->cell()->score, 0.01);
        $this->assertTrue($this->cell()->breakdown['bus_stop']['capped']);
    }

    public function test_dumping_reports_feed_removal_risk_not_demand(): void
    {
        Report::factory()->approved()->create(['category' => Report::CATEGORY_DUMPING, 'latitude' => self::LAT, 'longitude' => self::LNG]);

        app(DemandScoreService::class)->recompute();

        $this->assertEqualsWithDelta(0.0, $this->cell()->score, 0.01);
        $this->assertEqualsWithDelta(1.0, $this->cell()->dumping_score, 0.01);
        $this->assertSame($this->cell()->geohash6, app(DemandScoreService::class)->dumpingRisk()->first()->geohash6);
    }

    public function test_recompute_replaces_previous_cells_and_command_reports_count(): void
    {
        DemandProxy::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG]);
        app(DemandScoreService::class)->recompute();
        DemandProxy::query()->delete();
        User::factory()->create();

        $this->artisan('demand:recompute')->expectsOutputToContain('0 格')->assertSuccessful();

        $this->assertSame(0, DemandCell::count());
    }
}
