<?php

namespace Tests\Feature;

use App\Jobs\ModerateReportImage;
use App\Models\Report;
use App\Models\ReportCategory;
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

    /**
     * v0.3 五次回饋 item 1：矮螢幕（如 iPhone SE 375×667）表單比視窗高時，因外層
     * fixed items-end 無法捲動會把頂端（分類選項）頂出視窗外選不到。面板需自帶
     * max-h + overflow-y-auto 才能內部捲動看到完整表單。
     */
    public function test_open_report_modal_panel_is_scrollable(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('report-form')
            ->call('show')
            ->assertSet('open', true)
            ->assertSeeHtml('overflow-y-auto')
            ->assertSeeHtml('max-h-[90dvh]');
    }

    public function test_photoless_flood_report_is_created_with_geohash_and_depth(): void
    {
        $user = User::factory()->create();

        // 災時最低摩擦：不用照片、不用描述也能回報
        $this->submitReport($user, ['extraValue' => 50])
            ->assertHasNoErrors()
            ->assertSet('submitted', true)
            ->assertDispatched('report-submitted');

        $report = Report::sole();
        $this->assertSame(Report::CATEGORY_FLOOD, $report->category);
        $this->assertSame(50, $report->extra_value);
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
            'extraValue' => 50,
        ])->assertHasNoErrors();

        $this->assertNull(Report::sole()->extra_value);
    }

    public function test_admin_created_category_is_immediately_reportable(): void
    {
        ReportCategory::create([
            'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
            'color' => '#22c55e', 'sort_order' => 99, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $this->submitReport($user, ['category' => 'landslide']);

        $this->assertDatabaseHas('reports', ['category' => 'landslide']);
    }

    public function test_deactivated_category_is_rejected_for_new_reports(): void
    {
        ReportCategory::where('slug', 'power_outage')->first()->update(['is_active' => false]);

        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_POWER_OUTAGE])
            ->assertHasErrors(['category']);
    }

    public function test_extra_value_outside_category_config_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_FLOOD, 'extraValue' => 501])
            ->assertHasErrors(['extraValue']);
    }

    public function test_extra_value_boundary_follows_category_db_config_not_hardcoded_range(): void
    {
        ReportCategory::where('slug', 'flood')->first()->update([
            'extra_field' => ['label' => '積水深度', 'unit' => 'cm', 'min' => 0, 'max' => 300],
        ]);
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_FLOOD, 'extraValue' => 400])
            ->assertHasErrors(['extraValue']);
    }

    public function test_extra_value_is_nulled_for_category_without_extra_field(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_ROAD_BLOCKED, 'extraValue' => 50]);

        $this->assertNull(Report::latest('id')->first()->extra_value);
    }

    /**
     * 前端把照片壓到長邊 1024 再上傳（省災時手機流量）。wire:model 會在 change 當下上傳原圖，
     * 而 Livewire 只聽 change、不理壓縮後補發的 input 事件——結果一直上傳手機原圖
     * （2026-10-01 審查發現；瀏覽器實測 5.9MB 原圖修正前照傳、修正後 88KB）。JS 無測試環境，檢查接線。
     */
    public function test_photo_input_uploads_the_compressed_file_instead_of_the_original(): void
    {
        $form = file_get_contents(resource_path('views/livewire/report-form.blade.php'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('wire:model="photo"', $form);
        $this->assertStringContainsString("wire.upload('photo', compressed", $app);
    }
}

