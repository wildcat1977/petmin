<?php

namespace Tests\Feature;

use App\Models\DemandProxy;
use App\Models\TrashBin;
use App\Services\DemandScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 缺桶報表：行政區排行（提案說「哪一區最缺」、對應環保局依區分配資源）與候選地點明細表
 * （可篩選排序、CSV 照篩選匯出，讓會勘名單直接從這裡拉）。
 */
class DemandReportTest extends TestCase
{
    use RefreshDatabase;

    private const XINYI = [25.0330, 121.5654];   // 信義區（台北 101）

    private const NANGANG = [25.0553, 121.6071]; // 南港區（南港站一帶）

    protected function setUp(): void
    {
        parent::setUp();
        config(['petmin.demand.hotspot_min_score' => 5.0]);
        // 南港：兩個缺桶熱點（公車 4 ×（1+加成 1）= 8）、兩個袋箱都 100m 內沒有清潔箱
        DemandProxy::factory()->count(8)->create(['latitude' => self::NANGANG[0], 'longitude' => self::NANGANG[1]]);
        DemandProxy::factory()->count(8)->create(['latitude' => self::NANGANG[0], 'longitude' => self::NANGANG[1] + 0.01]); // 往東 1km（南港展覽館一帶）
        TrashBin::factory()->dogBagBox()->create(['district' => '南港區', 'latitude' => self::NANGANG[0], 'longitude' => self::NANGANG[1]]);
        TrashBin::factory()->dogBagBox()->create(['district' => '南港區', 'latitude' => self::NANGANG[0], 'longitude' => self::NANGANG[1] + 0.01]);
        // 信義：有桶顧著（公車 4、200m 外 3 組清潔箱扣 2.5）——低分候選、袋箱旁就有桶
        DemandProxy::factory()->count(8)->create(['latitude' => self::XINYI[0], 'longitude' => self::XINYI[1]]);
        TrashBin::factory()->create(['district' => '信義區', 'box_count' => 3, 'latitude' => self::XINYI[0], 'longitude' => self::XINYI[1] + 0.00198]); // 約 200m
        TrashBin::factory()->dogBagBox()->create(['district' => '信義區', 'latitude' => self::XINYI[0], 'longitude' => self::XINYI[1] + 0.0023]);
        app(DemandScoreService::class)->recompute();
    }

    public function test_district_ranking_counts_hotspots_bins_and_bag_gaps(): void
    {
        $rows = collect(app(DemandScoreService::class)->districtRanking())->keyBy('district');

        $this->assertCount(12, $rows);
        $this->assertSame('南港區', $rows->keys()->first(), '依缺桶熱點數排序');
        $this->assertSame(2, $rows['南港區']['hotspots']);
        $this->assertSame(2, $rows['南港區']['bag_boxes']);
        $this->assertSame(2, $rows['南港區']['bag_gaps']);
        $this->assertSame(0, $rows['南港區']['clean_boxes']);
        $this->assertSame(0, $rows['信義區']['hotspots']);
        $this->assertSame(3, $rows['信義區']['clean_boxes']);
        $this->assertSame(0, $rows['信義區']['bag_gaps']);
    }

    public function test_table_filters_by_district_and_sorts_by_column(): void
    {
        Livewire::test('demand-report')
            ->assertSee('南港區')
            ->set('district', '信義區')
            ->assertViewHas('candidates', fn ($page) => $page->total() === 1 && $page->first()->district === '信義區')
            ->set('district', '')
            ->call('sortBy', 'nearest_bin_m')                // 第一次點：由大到小（離桶最遠的先）
            ->assertSet('sort', 'nearest_bin_m')
            ->assertViewHas('candidates', fn ($page) => $page->first()->district === '南港區')
            ->call('sortBy', 'nearest_bin_m')                // 再點一次：反轉
            ->assertSet('dir', 'asc')
            ->assertViewHas('candidates', fn ($page) => $page->first()->district === '信義區');
    }

    public function test_sort_column_is_whitelisted(): void
    {
        Livewire::test('demand-report')->call('sortBy', 'breakdown; drop table users')->assertSet('sort', 'score');
    }

    public function test_csv_export_follows_district_filter(): void
    {
        $csv = $this->get('/candidates/export?district=南港區')->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines); // 表頭 + 南港 2 個候選地點
        $this->assertStringContainsString('行政區', $lines[0]);
        $this->assertStringContainsString('南港區', $lines[1]);
        $this->assertStringNotContainsString('信義區', $csv);
    }
}
