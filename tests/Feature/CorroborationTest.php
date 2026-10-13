<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 佐證機制：同一個桶（或同 geohash7 網格）、同分類，24 小時內 2 個不同帳號回報
 * → 該群自動升級 corroborated。需求分數會把佐證回報加倍計算，所以
 * 錯誤升級會放大單一帳號的洗版、漏升級會讓真實需求被低估。
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
            'category' => Report::CATEGORY_FULL,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }

    public function test_two_distinct_users_on_same_bin_upgrade_whole_group(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $bin = TrashBin::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG]);

        $first = $this->makeReport($a, ['trash_bin_id' => $bin->id]);
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $first->fresh()->verification_level);

        $second = $this->makeReport($b, ['trash_bin_id' => $bin->id]);

        $this->assertSame(Report::VERIFICATION_CORROBORATED, $first->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $second->fresh()->verification_level);
    }

    public function test_same_cell_without_bin_also_corroborates(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $first = $this->makeReport($a, ['category' => Report::CATEGORY_NEED_BIN]);
        $second = $this->makeReport($b, ['category' => Report::CATEGORY_NEED_BIN]);

        // 「這裡需要桶」沒有桶可掛，退回 geohash7 網格判定
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $first->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_CORROBORATED, $second->fresh()->verification_level);
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
        [$a, $b] = User::factory()->count(2)->create();

        $stale = $this->makeReport($a, ['created_at' => now()->subHours(25)]);
        $recent = $this->makeReport($b);

        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $recent->fresh()->verification_level);
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $stale->fresh()->verification_level);
    }

    public function test_different_category_in_same_cell_does_not_cross_corroborate(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $this->makeReport($a);
        $other = $this->makeReport($b, ['category' => Report::CATEGORY_MISSING]);

        // 「滿了」1 人 + 「消失了」1 人 ≠ 任何一類達 2 人
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $other->fresh()->verification_level);
        $this->assertSame(0, Report::where('verification_level', Report::VERIFICATION_CORROBORATED)->count());
    }

    public function test_rejected_reports_do_not_count_toward_distinct_users(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $this->makeReport($a, ['status' => Report::STATUS_REJECTED]);
        $last = $this->makeReport($b);

        // 內容審核退件（垃圾/違規）不得貢獻佐證
        $this->assertSame(Report::VERIFICATION_UNVERIFIED, $last->fresh()->verification_level);
    }

    public function test_official_verification_is_never_downgraded_or_touched(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $official = $this->makeReport($a);
        $official->update(['verification_level' => Report::VERIFICATION_OFFICIAL]);

        $this->makeReport($b);

        $this->assertSame(Report::VERIFICATION_OFFICIAL, $official->fresh()->verification_level);
    }
}
