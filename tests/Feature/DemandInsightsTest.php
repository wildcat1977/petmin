<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use App\Services\DemandScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 數據判讀頁的統計是提案論點的來源（「桶留在觀光區」「各區差 17 倍」「只有 43% 袋箱附近有桶」），
 * 數字要從資料算出來、可重現，不能是寫死在文案裡的。
 */
class DemandInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 中正：景點商圈 1 處 5 組；逐點清潔箱 2 組（一組在景點 200m 內、一組在 2km 外）；袋箱 1 個就在桶旁
        TrashBin::factory()->create(['source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA, 'district' => '中正區', 'box_count' => 5, 'latitude' => 25.0400, 'longitude' => 121.5100]);
        TrashBin::factory()->create(['source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX, 'district' => '中正區', 'latitude' => 25.0418, 'longitude' => 121.5100]);
        TrashBin::factory()->create(['source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX, 'district' => '中正區', 'latitude' => 25.0580, 'longitude' => 121.5100]);
        TrashBin::factory()->dogBagBox()->create(['district' => '中正區', 'latitude' => 25.0581, 'longitude' => 121.5100]);
        // 南港：沒有清潔箱、袋箱 2 個
        TrashBin::factory()->dogBagBox()->create(['district' => '南港區', 'latitude' => 25.0553, 'longitude' => 121.6071]);
        TrashBin::factory()->dogBagBox()->create(['district' => '南港區', 'latitude' => 25.0553, 'longitude' => 121.6171]);
    }

    public function test_insights_quantify_tourist_bias_and_bag_box_coverage(): void
    {
        $i = app(DemandScoreService::class)->insights();

        $this->assertSame(2, $i['clean_points']);
        $this->assertSame(1, $i['area_sites']);
        $this->assertSame(5, $i['area_sets']);
        $this->assertSame(50, $i['points_near_areas_pct']);   // 2 組逐點清潔箱有 1 組在景點商圈 500m 內
        $this->assertSame(3, $i['bag_boxes']);
        $this->assertSame(33, $i['bags_with_bin_100m_pct']);  // 3 個袋箱只有 1 個 100m 內有桶

        // 每個袋箱分到幾組清潔箱：中正 (5+2)/1 = 7.0、南港 0/2 = 0
        $this->assertSame('中正區', $i['sets_per_bag']['max']['district']);
        $this->assertEqualsWithDelta(7.0, $i['sets_per_bag']['max']['ratio'], 0.01);
        $this->assertSame('南港區', $i['sets_per_bag']['min']['district']);
    }

    public function test_insights_page_shows_the_numbers_behind_the_proposal(): void
    {
        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/candidates')->assertOk()->getContent();

        $this->assertStringContainsString('數據判讀', $html);
        $this->assertStringContainsString('33%', $html);           // 袋箱附近有桶的比例
        $this->assertStringContainsString('news.ltn.com.tw', $html); // 新北撤桶的出處
    }
}
