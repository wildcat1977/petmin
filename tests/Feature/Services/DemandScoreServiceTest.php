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
 * 只算已核准回報、佐證回報加倍、現存桶扣分、單一來源有上限、家戶垃圾另算撤除風險。
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
            'mrt' => 3.0, 'bus_stop' => 0.5, 'dog_park' => 3.0, 'park' => 1.5, 'petition' => 1.0,
            'report_need_bin' => 2.0, 'report_full' => 1.0, 'corroborated_multiplier' => 2.0, 'existing_bin_penalty' => 2.5,
        ], 'petmin.demand.caps' => ['bus_stop' => 4.0, 'mrt' => 6.0]]);
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
