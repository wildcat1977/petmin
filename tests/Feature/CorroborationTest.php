<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 佐證機制（spec §4.3 / 驗收 §1-4）：
 * 同 geohash7 網格內，3 個不同帳號在 6 小時內回報同類災情
 * → 該群點位自動升級 corroborated。
 *
 * 這是災情可信度分層的核心：EOC 與民眾地圖據此決定視覺層級，
 * 錯誤升級會放大假訊息、漏升級會延誤應變。
 */
class CorroborationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 25.0330;

    private const LNG = 121.5654;

    private function makeReport(User $user, array $overrides = []): Report
    {
        return Report::factory()->create(array_merge([
            'user_id' => $user->id,
            'category' => Report::CATEGORY_FLOOD,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }

    public function test_three_distinct_users_in_window_upgrade_whole_group(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();

        $first = $this->makeReport($a);
        $second = $this->makeReport($b);
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $first->fresh()->verification_level);

        $third = $this->makeReport($c);

        // 第三個不同帳號寫入後，該群全部升級
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $first->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $second->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $third->fresh()->verification_level);
    }

    public function test_same_user_repeating_does_not_corroborate(): void
    {
        $user = User::factory()->create();

        $reports = collect(range(1, 4))->map(fn () => $this->makeReport($user));

        // 單一帳號洗版不能自我佐證
        $reports->each(fn (Report $r) => $this->assertSame(
            Report::VERIFICATION_UNVERIFIED,
            $r->fresh()->verification_level,
        ));
    }

    public function test_reports_outside_sliding_window_do_not_count(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();

        $stale = $this->makeReport($a, ['created_at' => now()->subHours(7)]);
        $this->makeReport($b);
        $recent = $this->makeReport($c);

        // 窗外舊回報不計入 distinct user，也不被升級
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $recent->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $stale->fresh()->verification_level);
    }

    public function test_different_category_in_same_cell_does_not_cross_corroborate(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();

        $this->makeReport($a);
        $this->makeReport($b);
        $other = $this->makeReport($c, ['category' => Report::CATEGORY_POWER_OUTAGE]);

        // 淹水 2 人 + 停電 1 人 ≠ 任何一類達 3 人
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $other->fresh()->verification_level);
        $this->assertSame(
            0,
            Report::where('verification_level', Report::VERIFICATION_CORROBORATED)->count(),
        );
    }

    public function test_rejected_reports_do_not_count_toward_distinct_users(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();

        $this->makeReport($a, ['status' => Report::STATUS_REJECTED]);
        $this->makeReport($b);
        $last = $this->makeReport($c);

        // 內容審核退件（垃圾/違規）不得貢獻佐證
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $last->fresh()->verification_level);
    }

    public function test_official_verification_is_never_downgraded_or_touched(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();

        $official = $this->makeReport($a);
        $official->update(['verification_level' => Report::VERIFICATION_OFFICIAL]);

        $this->makeReport($b);
        $this->makeReport($c);

        // 佐證升級只觸碰 unverified；官方核實層級保持不變
        $this->assertSame(Report::VERIFICATION_OFFICIAL, $official->fresh()->verification_level);
    }
}
