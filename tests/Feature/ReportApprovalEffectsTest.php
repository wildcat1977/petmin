<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 回報核准後的連動（ReportObserver）：桶狀態跟著眾包驗證走、回報者加點與連續天數。
 * 這是「找回消失的垃圾桶」的閉環：沒有這段，回報只是資料不是行動。
 */
class ReportApprovalEffectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_missing_report_marks_bin_missing_and_exists_restores_it(): void
    {
        $bin = TrashBin::factory()->create();
        $report = Report::factory()->create(['category' => Report::CATEGORY_MISSING, 'trash_bin_id' => $bin->id]);
        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->fresh()->status);

        $report->update(['status' => Report::STATUS_APPROVED]);

        $this->assertSame(TrashBin::STATUS_MISSING, $bin->fresh()->status);
        $this->assertNotNull($bin->fresh()->last_verified_at);

        Report::factory()->create(['category' => Report::CATEGORY_EXISTS, 'trash_bin_id' => $bin->id, 'status' => Report::STATUS_APPROVED]);

        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->fresh()->status);
    }

    public function test_full_report_only_touches_last_verified_at(): void
    {
        $bin = TrashBin::factory()->create();

        Report::factory()->create(['category' => Report::CATEGORY_FULL, 'trash_bin_id' => $bin->id, 'status' => Report::STATUS_APPROVED]);

        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->fresh()->status);
        $this->assertNotNull($bin->fresh()->last_verified_at);
        $this->assertSame(1, $bin->fresh()->reports_count);
    }

    public function test_pending_report_does_not_change_bin_or_points(): void
    {
        $bin = TrashBin::factory()->create();
        $user = User::factory()->create();

        Report::factory()->create(['category' => Report::CATEGORY_MISSING, 'trash_bin_id' => $bin->id, 'user_id' => $user->id]);

        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->fresh()->status);
        $this->assertSame(0, $user->fresh()->points);
    }

    public function test_approval_awards_points_once_and_tracks_streak(): void
    {
        config(['petmin.points_per_approved_report' => 10]);
        $user = User::factory()->create();

        $yesterday = Report::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $yesterday->update(['status' => Report::STATUS_APPROVED]);
        $this->assertSame(10, $user->fresh()->points);
        $this->assertSame(1, $user->fresh()->streak_days);

        $today = Report::factory()->create(['user_id' => $user->id]);
        $today->update(['status' => Report::STATUS_APPROVED]);
        $this->assertSame(20, $user->fresh()->points);
        $this->assertSame(2, $user->fresh()->streak_days);

        // 已核准的回報再 touch 一次不重複加點
        $today->update(['notified_at' => now()]);
        $this->assertSame(20, $user->fresh()->points);
    }

    public function test_streak_resets_after_a_gap_day(): void
    {
        $user = User::factory()->create(['streak_days' => 5, 'last_report_date' => now()->subDays(3)->toDateString()]);

        Report::factory()->create(['user_id' => $user->id, 'status' => Report::STATUS_APPROVED]);

        $this->assertSame(1, $user->fresh()->streak_days);
    }
}
