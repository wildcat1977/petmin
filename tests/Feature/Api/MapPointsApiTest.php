<?php

namespace Tests\Feature\Api;

use App\Jobs\GenerateReportPhotoVariants;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
            'category' => Report::CATEGORY_FULL,
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
        $this->makeReport(['category' => Report::CATEGORY_FULL]);
        $this->makeReport(['category' => Report::CATEGORY_MISSING]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16&categories=missing');

        $points = $res->assertOk()->json('points');
        $this->assertCount(1, $points);
        $this->assertSame(Report::CATEGORY_MISSING, $points[0]['cat']);
    }

    public function test_pending_is_visible_but_rejected_is_hidden(): void
    {
        $this->makeReport(['status' => Report::STATUS_PENDING]);
        $this->makeReport(['status' => Report::STATUS_REJECTED]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16');

        // 「可能有狀況」的資訊價值大於等核實 → pending 上圖；退件（垃圾內容）不上圖
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
        // 公開照片會排縮圖 job（測試環境 sync 當場執行），不可碰到真正的 storage
        Storage::fake('public');
        $this->makeReport(['image_path' => 'reports/a.jpg', 'status' => Report::STATUS_PENDING]);
        $this->makeReport(['image_path' => 'reports/b.jpg', 'status' => Report::STATUS_APPROVED]);

        $points = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->json('points');

        $withImg = collect($points)->filter(fn ($p) => isset($p['img']));
        // AI／人工審核通過前照片不得公開（spec §6.1-4）
        $this->assertCount(1, $withImg);
        $this->assertStringContainsString('reports/b.jpg', $withImg->first()['img']);
    }

    /**
     * 手機原圖（上傳只重新編碼、不縮小）動輒數 MB，彈窗只有 260×180。
     * 縮圖在點位 API 讀到「已公開」照片時才於背景產生，不改上傳流程（做法同洋流好店）。
     */
    public function test_approved_photo_without_medium_queues_generation_and_keeps_the_original(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->makeReport(['image_path' => 'reports/b.jpg', 'status' => Report::STATUS_APPROVED]);

        $point = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->json('points.0');

        // 縮圖還沒產生：彈窗先用原圖（不破圖）
        $this->assertStringEndsWith('/storage/reports/b.jpg', $point['img']);
        $this->assertArrayNotHasKey('img_md', $point);
        Queue::assertPushed(GenerateReportPhotoVariants::class, fn ($job) => $job->path === 'reports/b.jpg');
    }

    public function test_approved_photo_with_medium_serves_it_for_the_popup(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->makeReport(['image_path' => 'reports/b.jpg', 'status' => Report::STATUS_APPROVED]);
        Storage::disk('public')->put('reports/b-md.jpg', 'x');

        $point = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->json('points.0');

        // 彈窗用 md；點開仍看原圖
        $this->assertStringEndsWith('/storage/reports/b-md.jpg', $point['img_md']);
        $this->assertStringEndsWith('/storage/reports/b.jpg', $point['img']);
        Queue::assertNotPushed(GenerateReportPhotoVariants::class);
    }

    public function test_pending_photo_is_not_processed(): void
    {
        // 照片審核通過前不公開（spec §6.1-4），也不替它產生縮圖
        Queue::fake();
        Storage::fake('public');
        $this->makeReport(['image_path' => 'reports/a.jpg', 'status' => Report::STATUS_PENDING]);

        $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=16')->assertOk();

        Queue::assertNotPushed(GenerateReportPhotoVariants::class);
    }

    public function test_low_zoom_returns_geohash_clusters_with_dominant_category(): void
    {
        User::factory()->count(4)->create()->each(function (User $u) {
            $this->makeReport(['user_id' => $u->id]);
        });
        $this->makeReport(['category' => Report::CATEGORY_NEED_BIN]);

        $res = $this->getJson('/api/map/reports?bbox='.self::BBOX.'&zoom=12');

        $res->assertOk()->assertJsonPath('mode', 'clusters');
        $clusters = $res->json('clusters');
        $this->assertNotEmpty($clusters);
        $this->assertSame(5, collect($clusters)->sum('count'));
        // 同格內以數量最多的分類為 dominant
        $this->assertSame(Report::CATEGORY_FULL, $clusters[0]['dominant_category']);
    }

    public function test_points_are_capped_and_flagged_truncated(): void
    {
        config(['petmin.map.max_points' => 3]);
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
