<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 首頁的「我的回報審核結果」通知：連續回報時會疊好幾則蓋住地圖，
 * 每則都要有看得見的關閉鈕，多則時還要能一鍵全關（10-24 Summer 回報關不掉）。
 */
class MyReportStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewed_reports_show_with_close_buttons_and_close_all(): void
    {
        $user = User::factory()->create();
        Report::factory()->count(2)->create(['user_id' => $user->id, 'status' => Report::STATUS_APPROVED, 'reviewed_at' => now()]);
        Report::factory()->create(['user_id' => $user->id, 'status' => Report::STATUS_REJECTED, 'rejection_reason' => 'ai_auto', 'reviewed_at' => now()]);

        Livewire::actingAs($user)->test('my-report-status')
            ->assertSee('回報審核')
            ->assertSee('aria-label="關閉"', false)
            ->assertSee('全部關閉')
            ->assertSee('dismissAll()', false);
    }

    public function test_pending_reports_do_not_show_a_notice(): void
    {
        $user = User::factory()->create();
        Report::factory()->create(['user_id' => $user->id, 'status' => Report::STATUS_PENDING]);

        Livewire::actingAs($user)->test('my-report-status')->assertDontSee('全部關閉');
    }
}
