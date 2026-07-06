<?php

namespace Tests\Feature;

use App\Jobs\ModerateReportImage;
use App\Models\Report;
use App\Services\ImageModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * AI 審核 queue 後補（spec §6.1-2 / §6.2）：
 * 明確違規才自動退件；不吻合分類僅留待人工，不自動退件；
 * API 失敗 fail-open 留在 pending，不誤擋災情回報。
 */
class ModerateReportImageJobTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingReportWithPhoto(): Report
    {
        Storage::fake('public');
        Storage::disk('public')->put('reports/test.jpg', 'fake-jpeg');

        return Report::factory()->create([
            'image_path' => 'reports/test.jpg',
            'status' => Report::STATUS_PENDING,
        ]);
    }

    private function mockModeration(array $result): void
    {
        $mock = Mockery::mock(ImageModerationService::class);
        $mock->shouldReceive('moderate')->once()->andReturn($result);
        $this->app->instance(ImageModerationService::class, $mock);
    }

    public function test_confident_violation_is_auto_rejected(): void
    {
        $report = $this->makePendingReportWithPhoto();
        $this->mockModeration(['is_valid' => false, 'matches_category' => false, 'confidence' => 0.95, 'reason' => 'claude']);

        (new ModerateReportImage($report->id))->handle(app(ImageModerationService::class));

        $report->refresh();
        $this->assertSame(Report::STATUS_REJECTED, $report->status);
        $this->assertSame('ai_auto', $report->rejection_reason);
    }

    public function test_valid_and_matching_photo_is_approved_for_publication(): void
    {
        $report = $this->makePendingReportWithPhoto();
        $this->mockModeration(['is_valid' => true, 'matches_category' => true, 'confidence' => 0.9, 'reason' => 'claude']);

        (new ModerateReportImage($report->id))->handle(app(ImageModerationService::class));

        $this->assertSame(Report::STATUS_APPROVED, $report->fresh()->status);
    }

    public function test_category_mismatch_stays_pending_for_human_review(): void
    {
        $report = $this->makePendingReportWithPhoto();
        $this->mockModeration(['is_valid' => true, 'matches_category' => false, 'confidence' => 0.9, 'reason' => 'claude']);

        (new ModerateReportImage($report->id))->handle(app(ImageModerationService::class));

        // 不吻合 → 標記人工複核，不自動退件（spec §6.2）
        $this->assertSame(Report::STATUS_PENDING, $report->fresh()->status);
        $this->assertNull($report->fresh()->rejection_reason);
    }

    public function test_low_confidence_rejection_is_not_auto_applied(): void
    {
        $report = $this->makePendingReportWithPhoto();
        $this->mockModeration(['is_valid' => false, 'matches_category' => false, 'confidence' => 0.5, 'reason' => 'claude']);

        (new ModerateReportImage($report->id))->handle(app(ImageModerationService::class));

        $this->assertSame(Report::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_api_error_fails_open_and_keeps_report_pending(): void
    {
        $report = $this->makePendingReportWithPhoto();
        $this->mockModeration(['is_valid' => true, 'matches_category' => true, 'confidence' => 0.0, 'reason' => 'api_error']);

        (new ModerateReportImage($report->id))->handle(app(ImageModerationService::class));

        // 外部 API 掛掉不得影響回報流程；照片留待人工（spec §8 韌性）
        $this->assertSame(Report::STATUS_PENDING, $report->fresh()->status);
    }
}
