<?php

namespace Tests\Feature;

use App\Jobs\ModerateBinName;
use App\Models\BinName;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 打卡頁的佔領與命名：巡滿次數就跳「恭喜你佔領這個桶」；已被佔領的桶要佔領後重新巡滿、且過了保護期才搶得走，
 * 還在保護期就告訴他幾點再來。名字先過黑名單、再進 AI 佇列，審核通過前地圖上照掛舊名。
 */
class BinOccupationComponentTest extends TestCase
{
    use RefreshDatabase;

    private TrashBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['petmin.bin_naming.min_reports' => 2, 'petmin.bin_naming.protect_hours' => 8]);
        $this->bin = TrashBin::factory()->create();
        Queue::fake();
    }

    /** 每筆間隔 10 小時，避開冷卻與同時段人數上限 */
    private function patrol(User $user, int $times, int $startHoursAgo = 200): void
    {
        for ($i = 0; $i < $times; $i++) {
            Report::factory()->approved()->create([
                'user_id' => $user->id,
                'trash_bin_id' => $this->bin->id,
                'created_at' => now()->subHours($startHoursAgo - $i * 10),
            ]);
        }
    }

    private function occupy(User $owner, int $hoursAgo, string $name = '舊桶名'): BinName
    {
        return BinName::create(['trash_bin_id' => $this->bin->id, 'user_id' => $owner->id, 'name' => $name,
            'status' => BinName::STATUS_APPROVED, 'reviewed_at' => now()->subHours($hoursAgo)]);
    }

    public function test_reaching_minimum_on_unoccupied_bin_pops_congratulations_and_name_goes_to_review(): void
    {
        $user = User::factory()->create();
        $this->patrol($user, 2);

        Livewire::actingAs($user)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('恭喜你佔領這個桶')
            ->set('name', '  柴柴的  秘密基地 ')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('審核中')
            ->assertDontSee('恭喜你佔領這個桶');

        $binName = BinName::sole();
        $this->assertSame('柴柴的 秘密基地', $binName->name);
        $this->assertSame(BinName::STATUS_PENDING, $binName->status);
        Queue::assertPushed(ModerateBinName::class, fn ($job) => $job->binNameId === $binName->id);
        $this->assertNull($this->bin->fresh()->currentName, '審核通過前不公開');
    }

    public function test_not_enough_reports_cannot_name(): void
    {
        $user = User::factory()->create();
        $this->patrol($user, 1);

        Livewire::actingAs($user)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('再 1 次就能佔領')
            ->assertDontSee('恭喜你佔領這個桶')
            ->set('name', '我的桶')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(0, BinName::count());
    }

    public function test_takeover_needs_fresh_reports_after_occupation_and_old_name_stays_meanwhile(): void
    {
        $owner = User::factory()->create();
        $challenger = User::factory()->create();
        $this->patrol($challenger, 2, 200);     // 佔領前巡的不算
        $this->occupy($owner, 100);

        Livewire::actingAs($challenger)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('舊桶名')
            ->assertSee('再 2 次就能搶下這個桶')
            ->assertDontSee('恭喜你佔領這個桶');

        $this->patrol($challenger, 2, 50);
        Livewire::actingAs($challenger)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('恭喜你佔領這個桶')
            ->assertSee('「舊桶名」的佔領結束了')
            ->set('name', '新桶名')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('舊桶名', $this->bin->fresh()->currentName->name, '新名字通過前照掛舊名，沒有空白期');
    }

    public function test_enough_reports_during_protection_says_when_to_come_back(): void
    {
        config(['petmin.bin_naming.cooldown_hours' => 1]);
        $owner = User::factory()->create();
        $challenger = User::factory()->create();
        $this->occupy($owner, 3);
        foreach ([150, 90] as $minutesAgo) {
            Report::factory()->approved()->create(['user_id' => $challenger->id, 'trash_bin_id' => $this->bin->id, 'created_at' => now()->subMinutes($minutesAgo)]);
        }
        $comeBack = now()->addHours(5)->format('n/j H:i');

        Livewire::actingAs($challenger)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('目前的佔領還在保護期')
            ->assertSee($comeBack)
            ->assertDontSee('恭喜你佔領這個桶')
            ->set('name', '搶桶')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(1, BinName::count());
    }

    public function test_occupier_cannot_rename_to_reset_challengers(): void
    {
        $owner = User::factory()->create();
        $this->occupy($owner, 100);
        $this->patrol($owner, 2, 50);

        Livewire::actingAs($owner)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('你正在佔領這個桶')
            ->assertDontSee('恭喜你佔領這個桶')
            ->set('name', '改個名')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(1, BinName::count());
    }

    public function test_blocklisted_name_is_rejected_on_the_spot_without_calling_ai(): void
    {
        $user = User::factory()->create();
        $this->patrol($user, 2);

        Livewire::actingAs($user)->test('bin-occupation', ['binId' => $this->bin->id])
            ->set('name', '幹 你 娘')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(0, BinName::count());
        Queue::assertNotPushed(ModerateBinName::class);
    }

    public function test_one_pending_at_a_time_and_daily_submission_cap(): void
    {
        config(['petmin.bin_naming.daily_submissions' => 2]);
        $user = User::factory()->create();
        $this->patrol($user, 2);
        $component = Livewire::actingAs($user)->test('bin-occupation', ['binId' => $this->bin->id]);

        $component->set('name', '第一個名字')->call('submit')->assertHasNoErrors();
        $component->set('name', '第二個名字')->call('submit')->assertHasErrors('name');

        BinName::query()->update(['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai']);
        $component->set('name', '第二個名字')->call('submit')->assertHasNoErrors();
        BinName::query()->update(['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai']);
        $component->set('name', '第三個名字')->call('submit')->assertHasErrors('name');

        $this->assertSame(2, BinName::count());
    }

    public function test_private_owner_is_anonymous(): void
    {
        $owner = User::factory()->create(['name' => '老王', 'is_private' => true]);
        $this->occupy($owner, 10);

        Livewire::test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('舊桶名')
            ->assertSee('匿名巡桶員')
            ->assertDontSee('老王');
    }

    public function test_rejected_submission_shows_reason_to_its_author_only(): void
    {
        $user = User::factory()->create();
        $this->patrol($user, 2);
        BinName::create(['trash_bin_id' => $this->bin->id, 'user_id' => $user->id, 'name' => '某某某下台',
            'status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai', 'moderation_note' => '涉及政治人物']);

        Livewire::actingAs($user)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('某某某下台')
            ->assertSee('涉及政治人物')
            ->assertSee('恭喜你佔領這個桶', '被退件後可以再取一次');

        Livewire::actingAs(User::factory()->create())->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertDontSee('某某某下台');
    }

    public function test_checkin_page_includes_occupation_panel(): void
    {
        $this->withoutVite();
        TrashBin::factory()->create(['external_id' => 'clean_box-xyz']);

        $this->get('/b/clean_box-xyz')->assertOk()->assertSeeLivewire('bin-occupation')->assertSee('佔領榜');
    }
}
