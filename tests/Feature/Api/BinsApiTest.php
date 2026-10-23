<?php

namespace Tests\Feature\Api;

use App\Models\BinName;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * 點群要落在桶的實際重心，不是 geohash 格中心：放格中心會排成棋盤，
     * 桶都擠在格子一角時，點群位置跟放大後看到的桶差好幾條街，使用者點下去找不到桶。
     */
    public function test_cluster_sits_at_bins_centroid_not_geohash_cell_center(): void
    {
        TrashBin::factory()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);
        TrashBin::factory()->create(['latitude' => 25.0340, 'longitude' => 121.5664]);
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0350, 'longitude' => 121.5674]);

        $clusters = $this->getJson('/api/map/bins?bbox='.self::BBOX.'&zoom=12&kinds=clean_box,dog_bag_box')
            ->assertOk()->json('clusters');

        $this->assertCount(1, $clusters);
        $this->assertSame(3, $clusters[0]['count']);
        $this->assertEqualsWithDelta(25.0340, $clusters[0]['lat'], 0.00001);
        $this->assertEqualsWithDelta(121.5664, $clusters[0]['lng'], 0.00001);
    }

    /**
     * 前端用畫面像素距離聚合（markercluster）：一次拿全部點位，聚合點才會貼著它代表的桶，
     * 不會像伺服器端大格子聚合那樣，重心落在需求方格上沒有桶的地方。篩選照常生效。
     */
    public function test_points_mode_returns_all_matching_bins_without_bbox_or_zoom(): void
    {
        $near = TrashBin::factory()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);
        $far = TrashBin::factory()->create(['latitude' => 25.1500, 'longitude' => 121.4600]);
        TrashBin::factory()->missing()->create(['latitude' => 25.0331, 'longitude' => 121.5654]);
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0332, 'longitude' => 121.5654]);

        $res = $this->getJson('/api/map/bins?mode=points&kinds=clean_box')->assertOk()->assertJsonPath('mode', 'points');

        $this->assertEqualsCanonicalizing([$near->id, $far->id], collect($res->json('points'))->pluck('id')->all());
    }

    /** 官方清單移除的桶是「消失的桶」最直接的證據：地圖要能顯示環保局哪天把它從清單拿掉 */
    public function test_points_carry_official_removal_date(): void
    {
        $bin = TrashBin::factory()->missing()->create(['latitude' => 25.0330, 'longitude' => 121.5654, 'removed_from_source_at' => '2026-10-20 04:00:00']);

        $points = $this->getJson('/api/map/bins?mode=points&statuses=missing')->assertOk()->json('points');

        $this->assertSame('2026-10-20', collect($points)->firstWhere('id', $bin->id)['removed_at']);
    }

    /** 佔領命名：地圖只掛審核通過的最新名字；佔領者開隱私模式就匿名；code 讓 popup 連到打卡頁看佔領榜 */
    public function test_points_carry_approved_bin_name_owner_and_checkin_code(): void
    {
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-abc', 'latitude' => 25.0330, 'longitude' => 121.5654]);
        $owner = User::factory()->create(['name' => '小美']);
        BinName::create(['trash_bin_id' => $bin->id, 'user_id' => $owner->id, 'name' => '舊名字', 'status' => BinName::STATUS_APPROVED]);
        BinName::create(['trash_bin_id' => $bin->id, 'user_id' => $owner->id, 'name' => '新名字', 'status' => BinName::STATUS_APPROVED]);
        BinName::create(['trash_bin_id' => $bin->id, 'user_id' => $owner->id, 'name' => '待審名字', 'status' => BinName::STATUS_PENDING]);
        $unnamed = TrashBin::factory()->create(['external_id' => null, 'latitude' => 25.0331, 'longitude' => 121.5655]);

        $points = collect($this->getJson('/api/map/bins?mode=points')->assertOk()->json('points'));

        $this->assertSame(['新名字', '小美', 'clean_box-abc'], [$points->firstWhere('id', $bin->id)['nick'], $points->firstWhere('id', $bin->id)['owner'], $points->firstWhere('id', $bin->id)['code']]);
        $this->assertNull($points->firstWhere('id', $unnamed->id)['nick']);
        $this->assertSame((string) $unnamed->id, $points->firstWhere('id', $unnamed->id)['code']);

        $owner->update(['is_private' => true]);
        Cache::flush(); // 測試用 array cache
        $this->assertNull(collect($this->getJson('/api/map/bins?mode=points')->json('points'))->firstWhere('id', $bin->id)['owner']);
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
