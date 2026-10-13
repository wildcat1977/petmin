<?php

namespace Tests\Feature\Api;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 桶位 API：bbox 點位／聚合、狀態篩選（消失的桶預設不下發）、回報表單的吸附查詢。 */
class BinsApiTest extends TestCase
{
    use RefreshDatabase;

    private const BBOX = '121.50,24.99,121.63,25.10';

    public function test_high_zoom_returns_active_bins_in_bbox_and_hides_missing_by_default(): void
    {
        $active = TrashBin::factory()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);
        TrashBin::factory()->missing()->create(['latitude' => 25.0331, 'longitude' => 121.5654]);
        TrashBin::factory()->create(['latitude' => 25.5, 'longitude' => 121.9]); // bbox 外

        $res = $this->getJson('/api/map/bins?bbox='.self::BBOX.'&zoom=16')->assertOk()->assertJsonPath('mode', 'points');

        $this->assertSame([$active->id], collect($res->json('points'))->pluck('id')->all());
    }

    public function test_statuses_param_includes_missing_bins_and_kinds_param_filters(): void
    {
        TrashBin::factory()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);
        TrashBin::factory()->missing()->create(['latitude' => 25.0331, 'longitude' => 121.5654]);
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0332, 'longitude' => 121.5654]);

        $all = $this->getJson('/api/map/bins?bbox='.self::BBOX.'&zoom=16&statuses=active,missing')->json('points');
        $this->assertCount(3, $all);

        $bags = $this->getJson('/api/map/bins?bbox='.self::BBOX.'&zoom=16&kinds=dog_bag_box')->json('points');
        $this->assertCount(1, $bags);
        $this->assertSame(TrashBin::KIND_DOG_BAG_BOX, $bags[0]['kind']);
    }

    public function test_low_zoom_returns_clusters(): void
    {
        TrashBin::factory()->count(5)->create(['latitude' => 25.0330, 'longitude' => 121.5654]);

        $res = $this->getJson('/api/map/bins?bbox='.self::BBOX.'&zoom=12')->assertOk()->assertJsonPath('mode', 'clusters');

        $this->assertSame(5, collect($res->json('clusters'))->sum('count'));
    }

    public function test_nearby_snaps_to_closest_bin_within_snap_distance_only(): void
    {
        config(['petmin.bin_snap_meters' => 30]);
        $near = TrashBin::factory()->create(['address' => '近的桶', 'latitude' => 25.03310, 'longitude' => 121.5654]); // ~11m
        TrashBin::factory()->create(['address' => '遠的桶', 'latitude' => 25.0340, 'longitude' => 121.5654]);           // ~110m

        $this->getJson('/api/map/bins/nearby?lat=25.0330&lng=121.5654')
            ->assertOk()
            ->assertJsonPath('bin.id', $near->id)
            ->assertJsonPath('bin.name', '近的桶');

        $this->getJson('/api/map/bins/nearby?lat=25.0360&lng=121.5654')->assertOk()->assertJsonPath('bin', null);
    }

    public function test_nearby_ignores_missing_bins(): void
    {
        TrashBin::factory()->missing()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);

        $this->getJson('/api/map/bins/nearby?lat=25.0330&lng=121.5654')->assertOk()->assertJsonPath('bin', null);
    }

    public function test_invalid_bbox_is_rejected(): void
    {
        $this->getJson('/api/map/bins?bbox=abc&zoom=16')->assertStatus(422);
    }
}
