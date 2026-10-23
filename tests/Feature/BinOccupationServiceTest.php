<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use App\Services\BinOccupationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 佔領命名的有效次數：命名權是公開掛在地圖上的榮譽，規則要擋住「站在桶前連拍」與「揪團輪流灌」，
 * 只獎勵長期、真的有在巡這個桶的人（config petmin.bin_naming）。
 */
class BinOccupationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrashBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $this->bin = TrashBin::factory()->create();
    }

    private function report(User $user, string $at, array $attrs = []): Report
    {
        return Report::factory()->approved()->create(array_merge([
            'user_id' => $user->id,
            'trash_bin_id' => $this->bin->id,
            'created_at' => $at,
        ], $attrs));
    }

    private function counts(): array
    {
        return app(BinOccupationService::class)->standings($this->bin)
            ->mapWithKeys(fn ($row) => [$row['user']->id => $row['count']])->all();
    }

    public function test_same_user_counts_once_per_cooldown(): void
    {
        $user = User::factory()->create();
        $this->report($user, '2026-10-20 08:00');
        $this->report($user, '2026-10-20 08:05'); // 連拍
        $this->report($user, '2026-10-20 15:59'); // 7:59 後，仍在冷卻
        $this->report($user, '2026-10-20 16:00'); // 滿 8 小時

        $this->assertSame([$user->id => 2], $this->counts());
    }

    public function test_cooldown_restarts_from_last_counted_report_not_last_attempt(): void
    {
        // 冷卻中的回報不重設計時：不然勤勞的人每 7 小時回報一次反而永遠只算 1 次
        $user = User::factory()->create();
        $this->report($user, '2026-10-20 00:00');
        $this->report($user, '2026-10-20 07:00');
        $this->report($user, '2026-10-20 08:00');

        $this->assertSame([$user->id => 2], $this->counts());
    }

    public function test_at_most_three_people_count_within_one_window(): void
    {
        [$a, $b, $c, $d] = User::factory()->count(4)->create();
        $this->report($a, '2026-10-20 08:00');
        $this->report($b, '2026-10-20 09:00');
        $this->report($c, '2026-10-20 10:00');
        $this->report($d, '2026-10-20 11:00'); // 第 4 人，同一時段不計
        $this->report($d, '2026-10-20 16:01'); // a 的那筆滑出 8 小時窗，名額空出來

        $this->assertSame([$a->id => 1, $b->id => 1, $c->id => 1, $d->id => 1], $this->counts());
    }

    public function test_only_recent_approved_reports_on_this_bin_count(): void
    {
        $user = User::factory()->create();
        $this->report($user, '2026-07-24 11:00');                                         // 91 天前
        $this->report($user, '2026-10-01 08:00', ['status' => Report::STATUS_PENDING]);    // 未核准
        $this->report($user, '2026-10-02 08:00', ['status' => Report::STATUS_REJECTED]);   // 退件
        $this->report($user, '2026-10-03 08:00', ['trash_bin_id' => TrashBin::factory()->create()->id]); // 別的桶
        $this->report($user, '2026-10-04 08:00');

        $this->assertSame([$user->id => 1], $this->counts());
    }

    public function test_banned_users_do_not_count_and_do_not_take_window_slots(): void
    {
        $banned = User::factory()->banned()->create();
        [$a, $b, $c] = User::factory()->count(3)->create();
        $this->report($banned, '2026-10-20 08:00');
        $this->report($a, '2026-10-20 09:00');
        $this->report($b, '2026-10-20 10:00');
        $this->report($c, '2026-10-20 11:00');

        $this->assertSame([$a->id => 1, $b->id => 1, $c->id => 1], $this->counts());
    }

    public function test_leader_needs_minimum_and_ties_go_to_who_got_there_first(): void
    {
        config(['petmin.bin_naming.min_reports' => 2]);
        $service = app(BinOccupationService::class);
        [$early, $late] = User::factory()->count(2)->create();

        $this->report($early, '2026-10-10 08:00');
        $this->assertNull($service->leader($this->bin), '未達門檻不能命名');

        $this->report($late, '2026-10-11 08:00');
        $this->report($late, '2026-10-12 08:00'); // late 先到 2 次
        $this->report($early, '2026-10-13 08:00'); // early 後到 2 次

        $this->assertSame($late->id, $service->leader($this->bin)->id);
        $this->assertSame([$late->id, $early->id], $service->standings($this->bin)->map(fn ($r) => $r['user']->id)->all());
    }
}
