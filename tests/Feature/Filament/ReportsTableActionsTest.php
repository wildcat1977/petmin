<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 回報審核操作按鈕依狀態呈現：
 * 待審核只出主按鈕 核准 / 拒絕；核准後才有 官方核實 / 已通報 / 已排除，
 * 且三顆收進下拉選單，避免列面橫向溢出。
 */
class ReportsTableActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_pending_report_shows_approve_and_reject(): void
    {
        $report = Report::factory()->create(['status' => Report::STATUS_PENDING]);

        Livewire::actingAs($this->admin)
            ->test(ListReports::class)
            ->assertActionVisible(TestAction::make('approve')->table($report))
            ->assertActionVisible(TestAction::make('reject')->table($report));
    }

    public function test_pending_report_hides_post_approval_status_marks(): void
    {
        $report = Report::factory()->create(['status' => Report::STATUS_PENDING]);

        Livewire::actingAs($this->admin)
            ->test(ListReports::class)
            ->assertActionHidden(TestAction::make('mark_official')->table($report))
            ->assertActionHidden(TestAction::make('mark_notified')->table($report));
    }

    public function test_approved_report_hides_approve_and_shows_status_marks(): void
    {
        $report = Report::factory()->approved()->create();

        Livewire::actingAs($this->admin)
            ->test(ListReports::class)
            ->assertActionHidden(TestAction::make('approve')->table($report))
            ->assertActionVisible(TestAction::make('mark_official')->table($report));
    }

    public function test_approve_action_publishes_pending_report(): void
    {
        $report = Report::factory()->create(['status' => Report::STATUS_PENDING]);

        Livewire::actingAs($this->admin)
            ->test(ListReports::class)
            ->callAction(TestAction::make('approve')->table($report));

        $this->assertSame(Report::STATUS_APPROVED, $report->fresh()->status);
    }

    public function test_mark_official_sets_verification_on_approved_report(): void
    {
        $report = Report::factory()->approved()->create([
            'verification_level' => Report::VERIFICATION_UNVERIFIED,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListReports::class)
            ->callAction(TestAction::make('mark_official')->table($report));

        $this->assertSame(Report::VERIFICATION_OFFICIAL, $report->fresh()->verification_level);
    }
}
