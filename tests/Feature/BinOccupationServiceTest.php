<?php

namespace Tests\Feature;

use App\Models\BinName;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use App\Services\BinOccupationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 佔領命名的有效次數：命名權是公開掛在地圖上的榮譽，規則要擋住「站在桶前連拍」與「揪團輪流灌」，
 * 只獎勵長期、真的有在巡這個桶的人；易主後大家重新累積，佔領者有保護期（config petmin.bin_naming）。
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

    public function test_submitted_reports_count_until_rejected(): void
    {
        // 站在桶前就要能累積（AI 拿不準時會停在待審）；只有被 AI 或管理員退件的才扣掉
        $user = User::factory()->create();
        $this->report($user, '2026-07-24 11:00');                                         // 91 天前
        $this->report($user, '2026-10-01 08:00', ['status' => Report::STATUS_PENDING]);    // 待審：算
        $this->report($user, '2026-10-02 08:00', ['status' => Report::STATUS_REJECTED]);   // 退件：不算
        $this->report($user, '2026-10-03 08:00', ['trash_bin_id' => TrashBin::factory()->create()->id]); // 別的桶
        $this->report($user, '2026-10-04 08:00');

        $this->assertSame([$user->id => 2], $this->counts());
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

    public function test_unoccupied_bin_can_be_claimed_once_minimum_is_reached(): void
    {
        $service = app(BinOccupationService::class);
        $user = User::factory()->create();
        $this->report($user, '2026-10-20 00:00');
        $this->report($user, '2026-10-20 08:00');

        $this->assertSame(['collecting', 1], $this->stateAndLeft($service->status($this->bin, $user)));

        $this->report($user, '2026-10-20 16:00');
        $this->assertSame('eligible', $service->status($this->bin->fresh(), $user)['state']);
    }

    public function test_takeover_needs_fresh_reports_after_the_current_occupation_began(): void
    {
        // 易主一次大家重新累積：不然兩個常客累積夠了以後，每過保護期就能互搶
        $service = app(BinOccupationService::class);
        [$owner, $challenger] = User::factory()->count(2)->create();
        $this->report($challenger, '2026-10-18 00:00');
        $this->report($challenger, '2026-10-18 08:00');
        $this->report($challenger, '2026-10-18 16:00');
        $this->occupy($owner, '2026-10-19 00:00');
        $this->report($challenger, '2026-10-19 08:00');

        $this->assertSame(['collecting', 2], $this->stateAndLeft($service->status($this->bin->fresh(), $challenger)));
        $this->assertSame([$challenger->id => 1], app(BinOccupationService::class)->standings($this->bin, Carbon::parse('2026-10-19 00:00'))
            ->mapWithKeys(fn ($r) => [$r['user']->id => $r['count']])->all());

        $this->report($challenger, '2026-10-19 16:00');
        $this->report($challenger, '2026-10-20 00:00');
        $this->assertSame('eligible', $service->status($this->bin->fresh(), $challenger)['state']);
        $this->assertSame('occupier', $service->status($this->bin->fresh(), $owner)['state']);
    }

    public function test_enough_reports_inside_protection_window_tells_when_to_come_back(): void
    {
        // 預設冷卻 8 小時，3 次最快要 16 小時、保護期自然過了；冷卻調短時才會遇到，要告訴他幾點再來
        config(['petmin.bin_naming.cooldown_hours' => 1, 'petmin.bin_naming.protect_hours' => 8]);
        $service = app(BinOccupationService::class);
        [$owner, $challenger] = User::factory()->count(2)->create();
        $this->occupy($owner, '2026-10-23 08:00');
        foreach (['09:00', '10:00', '11:00'] as $at) {
            $this->report($challenger, "2026-10-23 {$at}");
        }

        $status = $service->status($this->bin->fresh(), $challenger);
        $this->assertSame('waiting', $status['state']);
        $this->assertSame('2026-10-23 16:00', $status['available_at']->format('Y-m-d H:i'));

        Carbon::setTestNow('2026-10-23 16:00:00');
        $this->assertSame('eligible', $service->status($this->bin->fresh(), $challenger)['state']);
    }

    public function test_zero_cooldown_lets_one_person_count_back_to_back_for_demo(): void
    {
        // demo 時把冷卻與保護期設成 0，碰 NFC 連續回報 3 次就要能當場佔領
        config(['petmin.bin_naming.cooldown_hours' => 0, 'petmin.bin_naming.protect_hours' => 0]);
        $service = app(BinOccupationService::class);
        [$owner, $user] = User::factory()->count(2)->create();
        $this->occupy($owner, '2026-10-23 11:00');
        foreach (['11:01', '11:02', '11:03'] as $at) {
            $this->report($user, "2026-10-23 {$at}");
        }

        $this->assertSame([$user->id => 3], $service->standings($this->bin, Carbon::parse('2026-10-23 11:00'))
            ->mapWithKeys(fn ($r) => [$r['user']->id => $r['count']])->all());
        $this->assertSame('eligible', $service->status($this->bin->fresh(), $user)['state']);
    }

    private function occupy(User $owner, string $at): void
    {
        BinName::create(['trash_bin_id' => $this->bin->id, 'user_id' => $owner->id, 'name' => '舊名字',
            'status' => BinName::STATUS_APPROVED, 'reviewed_at' => $at, 'created_at' => $at]);
    }

    private function stateAndLeft(array $status): array
    {
        return [$status['state'], $status['left']];
    }
}
