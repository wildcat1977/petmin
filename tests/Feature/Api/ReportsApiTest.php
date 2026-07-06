<?php

namespace Tests\Feature\Api;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use RefreshDatabase;

    private function approvedReport(array $attrs = []): Report
    {
        return Report::factory()->create(array_merge(['status' => Report::STATUS_APPROVED], $attrs));
    }

    public function test_returns_visible_reports_as_json(): void
    {
        $report = $this->approvedReport(['description' => 'test flood']);
        Report::factory()->create(['status' => Report::STATUS_REJECTED]);

        $response = $this->getJson('/api/v1/reports');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonStructure([
                'data' => [['id', 'category', 'latitude', 'longitude', 'address', 'description', 'image_url', 'reported_at', 'status']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_includes_all_visible_statuses(): void
    {
        // 可見狀態 = pending + approved（pending 點位上圖）；rejected 下圖
        Report::factory()->create(['status' => Report::STATUS_APPROVED]);
        Report::factory()->create(['status' => Report::STATUS_REJECTED]);
        Report::factory()->create(['status' => Report::STATUS_PENDING]);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_pending_report_photo_is_not_exposed(): void
    {
        // 內容審核通過前照片不得公開（pending 僅點位上圖）
        $pending = Report::factory()->create([
            'status' => Report::STATUS_PENDING,
            'image_path' => 'reports/pending.jpg',
        ]);
        $approved = $this->approvedReport(['image_path' => 'reports/approved.jpg']);

        $response = $this->getJson('/api/v1/reports')->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertNull($byId[$pending->id]['image_url']);
        $this->assertNotNull($byId[$approved->id]['image_url']);
    }

    public function test_filters_by_from_date(): void
    {
        Report::factory()->create([
            'status' => Report::STATUS_APPROVED,
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $recent = Report::factory()->create([
            'status' => Report::STATUS_APPROVED,
            'created_at' => '2026-06-01 10:00:00',
        ]);

        $this->getJson('/api/v1/reports?from=2026-05-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_filters_by_to_date(): void
    {
        $old = Report::factory()->create([
            'status' => Report::STATUS_APPROVED,
            'created_at' => '2026-01-01 10:00:00',
        ]);
        Report::factory()->create([
            'status' => Report::STATUS_APPROVED,
            'created_at' => '2026-06-01 10:00:00',
        ]);

        $this->getJson('/api/v1/reports?to=2026-03-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);
    }

    public function test_filters_by_from_and_to(): void
    {
        Report::factory()->create(['status' => Report::STATUS_APPROVED, 'created_at' => '2026-01-01 00:00:00']);
        $match = Report::factory()->create(['status' => Report::STATUS_APPROVED, 'created_at' => '2026-03-15 12:00:00']);
        Report::factory()->create(['status' => Report::STATUS_APPROVED, 'created_at' => '2026-06-01 00:00:00']);

        $this->getJson('/api/v1/reports?from=2026-03-01&to=2026-04-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_filters_by_category(): void
    {
        $flood = $this->approvedReport(['category' => Report::CATEGORY_FLOOD]);
        $roadBlocked = $this->approvedReport(['category' => Report::CATEGORY_ROAD_BLOCKED]);

        $this->getJson('/api/v1/reports?category=flood')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $flood->id);

        $this->getJson('/api/v1/reports?category=road_blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $roadBlocked->id);
    }

    public function test_rejects_invalid_category(): void
    {
        $this->getJson('/api/v1/reports?category=invalid')
            ->assertUnprocessable();
    }

    public function test_rejects_invalid_date(): void
    {
        $this->getJson('/api/v1/reports?from=not-a-date')
            ->assertUnprocessable();
    }

    public function test_pagination_meta_is_correct(): void
    {
        Report::factory()->count(5)->create(['status' => Report::STATUS_APPROVED]);

        $response = $this->getJson('/api/v1/reports?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_reported_at_includes_timezone(): void
    {
        $this->approvedReport();

        $response = $this->getJson('/api/v1/reports');
        $reportedAt = $response->json('data.0.reported_at');

        // ISO 8601 with offset e.g. 2026-05-10T18:00:00+08:00
        $this->assertMatchesRegularExpression('/\+\d{2}:\d{2}$/', $reportedAt);
    }
}
