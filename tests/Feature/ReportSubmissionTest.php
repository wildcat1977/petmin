<?php

namespace Tests\Feature;

use App\Jobs\ModerateReportImage;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 回報 pipeline（spec §6.1）：寫入即回應（AI 審核 queue 後補）、
 * 照片選填、淹水深度僅 flood 有效、反濫用 cooldown + 每小時上限。
 */
class ReportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function submitReport(User $user, array $set = [])
    {
        $component = Livewire::actingAs($user)->test('report-form')
            ->set('category', $set['category'] ?? Report::CATEGORY_FLOOD)
            ->set('latitude', $set['latitude'] ?? 25.0330)
            ->set('longitude', $set['longitude'] ?? 121.5654)
            ->set('description', $set['description'] ?? '');

        foreach ($set as $key => $value) {
            if (! in_array($key, ['category', 'latitude', 'longitude', 'description'], true)) {
                $component->set($key, $value);
            }
        }

        return $component->call('submit');
    }

    public function test_photoless_flood_report_is_created_with_geohash_and_depth(): void
    {
        $user = User::factory()->create();

        // 災時最低摩擦：不用照片、不用描述也能回報
        $this->submitReport($user, ['waterDepthCm' => 50])
            ->assertHasNoErrors()
            ->assertSet('submitted', true)
            ->assertDispatched('report-submitted');

        $report = Report::sole();
        $this->assertSame(Report::CATEGORY_FLOOD, $report->category);
        $this->assertSame(50, $report->water_depth_cm);
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $report->verification_level);
        // geohash7 由寫入時自動推導（佐證與聚合依賴它）
        $this->assertSame(7, strlen($report->geohash7));
    }

    public function test_invalid_category_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => 'rat'])->assertHasErrors(['category']);

        $this->assertSame(0, Report::count());
    }

    public function test_cooldown_blocks_rapid_resubmission(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user)->assertHasNoErrors();
        // cooldown 內第二次提交必須被擋（spec §6.1-5 反濫用）
        $this->submitReport($user)->assertHasErrors(['description']);

        $this->assertSame(1, Report::count());
    }

    public function test_hourly_limit_blocks_further_reports(): void
    {
        config(['hazdar.report_hourly_limit' => 3, 'hazdar.report_cooldown_seconds' => 0]);
        $user = User::factory()->create();

        Report::factory()->count(3)->create([
            'user_id' => $user->id,
            'created_at' => now()->subMinutes(10),
        ]);

        $this->submitReport($user)->assertHasErrors(['description']);
        $this->assertSame(3, Report::count());
    }

    public function test_banned_user_cannot_submit(): void
    {
        $user = User::factory()->create(['is_banned' => true]);

        $this->submitReport($user)->assertHasErrors(['description']);
        $this->assertSame(0, Report::count());
    }

    public function test_photo_submission_queues_moderation_instead_of_blocking(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $this->submitReport($user, ['photo' => UploadedFile::fake()->image('flood.jpg', 640, 480)])
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $report = Report::sole();
        // 寫入即回應：AI 審核不在請求生命週期內（spec §6.1-2 / §8）
        $this->assertSame(Report::STATUS_PENDING, $report->status);
        $this->assertNotNull($report->image_path);
        Queue::assertPushed(ModerateReportImage::class, fn ($job) => $job->reportId === $report->id);
    }

    public function test_water_depth_is_ignored_for_non_flood_categories(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, [
            'category' => Report::CATEGORY_POWER_OUTAGE,
            'waterDepthCm' => 50,
        ])->assertHasNoErrors();

        $this->assertNull(Report::sole()->water_depth_cm);
    }
}
