<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 巡桶貼紙列印頁：管理員限定；預設印示範位置附近的清潔箱，也可指定官方編號。 */
class BinStickerPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_non_admin_cannot_open(): void
    {
        $this->get('/print/bin-stickers')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/print/bin-stickers')->assertForbidden();
    }

    public function test_admin_gets_nearest_clean_boxes_around_demo_point_with_checkin_urls(): void
    {
        $demo = config('petmin.find_bin.demo_point');
        TrashBin::factory()->create(['external_id' => 'clean_box-near', 'address' => '北平東路口', 'latitude' => $demo['lat'] + 0.001, 'longitude' => $demo['lng']]);
        TrashBin::factory()->dogBagBox()->create(['external_id' => 'dog_bag_box-x', 'address' => '袋箱不印', 'latitude' => $demo['lat'], 'longitude' => $demo['lng']]);
        TrashBin::factory()->create(['external_id' => 'clean_box-far', 'address' => '太遠不印', 'latitude' => 25.20, 'longitude' => 121.40]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/print/bin-stickers')
            ->assertOk()
            ->assertSee('北平東路口')
            ->assertSee(url('/b/clean_box-near').'?via=qr', false)
            ->assertDontSee('袋箱不印')
            ->assertDontSee('太遠不印');
    }

    public function test_admin_can_pick_bins_by_code_in_given_order(): void
    {
        TrashBin::factory()->create(['external_id' => 'clean_box-a', 'address' => '甲桶']);
        TrashBin::factory()->create(['external_id' => 'clean_box-b', 'address' => '乙桶']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/print/bin-stickers?codes=clean_box-b,clean_box-a')
            ->assertOk()
            ->assertSeeInOrder(['乙桶', '甲桶']);
    }
}
