<?php

namespace Tests\Feature\Api;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 點位 API（spec §7.2 / 驗收 §1-3）：server-side bbox query 取代前端全量，
 * 高 zoom 回個別點位、低 zoom 回 geohash 聚合，payload 有上限。
 */
class MapPointsApiTest extends TestCase
{
    use RefreshDatabase;

    /** 台北市中心附近的 bbox */
    private const BBOX = '121.50,24.99,121.63,25.10';

    private function makeReport(array $overrides = []): Report
    {
        return Report::factory()->create(array_merge([
            'category' => Report::CATEGORY_FLOOD,
            'latitude' => 25.0330,
            'longitude' => 121.5654,
        ], $overrides));
    }

    public function test_high_zoom_returns_individual_points_within_bbox_only(): void
    {
        $inside = $this->makeReport();
        $this->makeReport(['latitude' => 25.5, 'longitude' => 121.9]); // bbox 外

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16');

        $res->assertOk()->assertJsonPath('mode', 'points');
        $points = $res->json('points');
        $this->assertCount(1, $points);
        $this->assertSame($inside->id, $points[0]['id']);
    }

    public function test_category_filter_narrows_results(): void
    {
        $this->makeReport(['category' => Report::CATEGORY_FLOOD]);
        $this->makeReport(['category' => Report::CATEGORY_POWER_OUTAGE]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16&categories=power_outage');

        $points = $res->assertOk()->json('points');
        $this->assertCount(1, $points);
        $this->assertSame(Report::CATEGORY_POWER_OUTAGE, $points[0]['cat']);
    }

    public function test_pending_is_visible_but_rejected_is_hidden(): void
    {
        $this->makeReport(['status' => Report::STATUS_PENDING]);
        $this->makeReport(['status' => Report::STATUS_REJECTED]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16');

        // 災時「可能有事」的價值大於等核實 → pending 上圖；退件（垃圾內容）不上圖
        $this->assertCount(1, $res->json('points'));
    }

    public function test_resolved_is_visible_by_default_with_state_field(): void
    {
        $this->makeReport(['resolved_at' => now()]);
        $this->makeReport(['notified_at' => now()]);
        $this->makeReport();

        $points = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->assertOk()->json('points');

        $states = collect($points)->pluck('st');
        $this->assertContains('resolved', $states);
        $this->assertContains('notified', $states);
        $this->assertCount(3, $points);
    }

    public function test_show_resolved_zero_hides_resolved(): void
    {
        $this->makeReport(['resolved_at' => now()]);
        $this->makeReport();

        $points = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16&show_resolved=0')
            ->assertOk()->json('points');

        $this->assertCount(1, $points);
        $this->assertArrayNotHasKey('st', $points[0]);
    }

    public function test_resolved_beats_notified_in_state_derivation(): void
    {
        $this->makeReport(['notified_at' => now()->subHour(), 'resolved_at' => now()]);

        $points = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->assertOk()->json('points');
        $this->assertSame('resolved', $points[0]['st']);
    }

    public function test_photo_url_exposed_only_after_content_approval(): void
    {
        $this->makeReport(['image_path' => 'reports/a.jpg', 'status' => Report::STATUS_PENDING]);
        $this->makeReport(['image_path' => 'reports/b.jpg', 'status' => Report::STATUS_APPROVED]);

        $points = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->json('points');

        $withImg = collect($points)->filter(fn ($p) => isset($p['img']));
        // AI／人工審核通過前照片不得公開（spec §6.1-4）
        $this->assertCount(1, $withImg);
        $this->assertStringContainsString('reports/b.jpg', $withImg->first()['img']);
    }

    public function test_low_zoom_returns_geohash_clusters_with_dominant_category(): void
    {
        User::factory()->count(4)->create()->each(function (User $u) {
            $this->makeReport(['user_id' => $u->id]);
        });
        $this->makeReport(['category' => Report::CATEGORY_ROAD_BLOCKED]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=12');

        $res->assertOk()->assertJsonPath('mode', 'clusters');
        $clusters = $res->json('clusters');
        $this->assertNotEmpty($clusters);
        $this->assertSame(5, collect($clusters)->sum('count'));
        // 同格內以數量最多的分類為 dominant
        $this->assertSame(Report::CATEGORY_FLOOD, $clusters[0]['dominant_category']);
    }

    public function test_points_are_capped_and_flagged_truncated(): void
    {
        config(['hazdar.map.max_points' => 3]);
        Report::factory()->count(5)->create([
            'latitude' => 25.0330,
            'longitude' => 121.5654,
        ]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16');

        // 單次回應筆數上限（payload 保護，驗收 §1-3）
        $this->assertCount(3, $res->json('points'));
        $this->assertTrue($res->json('truncated'));
    }

    public function test_invalid_bbox_is_rejected(): void
    {
        $this->getJson('/api/map/reports?bbox=abc&zoom=16')->assertStatus(422);
        $this->getJson('/api/map/reports?bbox=121.6,25.1,121.5,25.0&zoom=16')->assertStatus(422);
    }
}
