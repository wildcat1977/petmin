<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 垃圾桶多快會滿（展示版）：一定標明是示意數字；只列示範位置附近的清潔箱；同一個桶的示意值固定。 */
class FillSpeedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_nearby_clean_boxes_with_illustrative_label(): void
    {
        $this->withoutVite();
        $demo = config('petmin.find_bin.demo_point');
        TrashBin::factory()->create(['external_id' => 'clean_box-near', 'address' => '北平東路口', 'latitude' => $demo['lat'] + 0.001, 'longitude' => $demo['lng']]);
        TrashBin::factory()->dogBagBox()->create(['address' => '袋箱不列', 'latitude' => $demo['lat'], 'longitude' => $demo['lng']]);

        $first = $this->get('/fill-speed')->assertOk()
            ->assertSee('以下是示意數字')
            ->assertSee('北平東路口')
            ->assertDontSee('袋箱不列');

        $hours = 6 + crc32('clean_box-near') % 67;
        $first->assertSee("約 {$hours} 小時滿一次");
        $this->get('/fill-speed')->assertSee("約 {$hours} 小時滿一次"); // 重新整理不會變
    }
}
