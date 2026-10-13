<?php

namespace Tests\Feature\Services;

use App\Models\TrashBin;
use App\Services\BagBoxGapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 「有袋沒桶」是提案第一張圖：狗便袋箱 N 公尺內沒有 active 清潔箱才算 gap。 */
class BagBoxGapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bag_box_without_nearby_clean_box_is_a_gap_and_with_one_is_not(): void
    {
        $lonely = TrashBin::factory()->dogBagBox()->create(['address' => '孤單袋箱', 'latitude' => 25.0330, 'longitude' => 121.5654, 'box_count' => 3]);
        $served = TrashBin::factory()->dogBagBox()->create(['address' => '有桶袋箱', 'latitude' => 25.0500, 'longitude' => 121.5500]);
        // 離 served 約 50m 的清潔箱
        TrashBin::factory()->create(['latitude' => 25.0504, 'longitude' => 121.5502]);
        // 離 lonely 約 200m：超過 100m 門檻
        TrashBin::factory()->create(['latitude' => 25.0348, 'longitude' => 121.5654]);

        $gaps = app(BagBoxGapService::class)->gaps(100);

        $this->assertSame([$lonely->id], $gaps->pluck('id')->all());
        $this->assertEqualsWithDelta(200, $gaps->first()['nearest_m'], 10);
        $this->assertSame(3, $gaps->first()['box_count']);
        $this->assertNotContains($served->id, $gaps->pluck('id'));
    }

    public function test_missing_clean_boxes_do_not_serve_a_bag_box(): void
    {
        $bag = TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);
        TrashBin::factory()->missing()->create(['latitude' => 25.0331, 'longitude' => 121.5654]);

        $this->assertSame([$bag->id], app(BagBoxGapService::class)->gaps(100)->pluck('id')->all());
    }

    public function test_gap_report_command_lists_and_exports_csv(): void
    {
        TrashBin::factory()->dogBagBox()->create(['address' => '孤單袋箱', 'district' => '中正區', 'latitude' => 25.0330, 'longitude' => 121.5654]);
        $csv = storage_path('app/test-gaps.csv');

        $this->artisan('bins:gap-report', ['--csv' => $csv])
            ->expectsOutputToContain('有袋沒桶：1 處')
            ->assertSuccessful();

        $this->assertStringContainsString('孤單袋箱', file_get_contents($csv));
        unlink($csv);
    }
}
