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
 * 打卡頁的佔領榜與命名：只有第一名（且達門檻）能命名；名字先過黑名單、再進 AI 佇列，審核通過前不公開。
 * 被超越時舊名照掛，直到新的第一名命名。
 */
class BinOccupationComponentTest extends TestCase
{
    use RefreshDatabase;

    private TrashBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['petmin.bin_naming.min_reports' => 2]);
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

    public function test_leader_can_name_and_it_goes_to_ai_review(): void
    {
        $leader = User::factory()->create();
        $this->patrol($leader, 2);

        Livewire::actingAs($leader)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('你是第一名')
            ->set('name', '  柴柴的  秘密基地 ')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('審核中');

        $binName = BinName::sole();
        $this->assertSame('柴柴的 秘密基地', $binName->name);
        $this->assertSame(BinName::STATUS_PENDING, $binName->status);
        Queue::assertPushed(ModerateBinName::class, fn ($job) => $job->binNameId === $binName->id);
        $this->assertNull($this->bin->fresh()->currentName, '審核通過前不公開');
    }

    public function test_non_leader_or_below_minimum_cannot_name(): void
    {
        $leader = User::factory()->create();
        $runnerUp = User::factory()->create();
        $this->patrol($leader, 3);
        $this->patrol($runnerUp, 2, 150);

        Livewire::actingAs($runnerUp)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertDontSee('你是第一名')
            ->set('name', '我的桶')
            ->call('submit')
            ->assertHasErrors('name');

        $newbie = User::factory()->create();
        $solo = TrashBin::factory()->create();
        Report::factory()->approved()->create(['user_id' => $newbie->id, 'trash_bin_id' => $solo->id]);
        Livewire::actingAs($newbie)->test('bin-occupation', ['binId' => $solo->id])
            ->assertSee('再 1 次')
            ->set('name', '我的桶')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(0, BinName::count());
    }

    public function test_blocklisted_name_is_rejected_on_the_spot_without_calling_ai(): void
    {
        $leader = User::factory()->create();
        $this->patrol($leader, 2);

        Livewire::actingAs($leader)->test('bin-occupation', ['binId' => $this->bin->id])
            ->set('name', '幹 你 娘')
            ->call('submit')
            ->assertHasErrors('name');

        $this->assertSame(0, BinName::count());
        Queue::assertNotPushed(ModerateBinName::class);
    }

    public function test_one_pending_at_a_time_and_daily_submission_cap(): void
    {
        config(['petmin.bin_naming.daily_submissions' => 2]);
        $leader = User::factory()->create();
        $this->patrol($leader, 2);
        $component = Livewire::actingAs($leader)->test('bin-occupation', ['binId' => $this->bin->id]);

        $component->set('name', '第一個名字')->call('submit')->assertHasNoErrors();
        $component->set('name', '第二個名字')->call('submit')->assertHasErrors('name');

        BinName::query()->update(['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai']);
        $component->set('name', '第二個名字')->call('submit')->assertHasNoErrors();
        BinName::query()->update(['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai']);
        $component->set('name', '第三個名字')->call('submit')->assertHasErrors('name');

        $this->assertSame(2, BinName::count());
    }

    public function test_old_name_stays_until_new_leader_names_and_private_owner_is_anonymous(): void
    {
        $old = User::factory()->create(['name' => '老王', 'is_private' => true]);
        BinName::create(['trash_bin_id' => $this->bin->id, 'user_id' => $old->id, 'name' => '老桶名', 'status' => BinName::STATUS_APPROVED]);
        $newLeader = User::factory()->create(['name' => '小美']);
        $this->patrol($newLeader, 2);

        Livewire::test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('老桶名')
            ->assertSee('匿名巡桶員')
            ->assertDontSee('老王')
            ->assertSee('小美')
            ->assertSee('2 次');
    }

    public function test_rejected_submission_shows_reason_to_its_author(): void
    {
        $leader = User::factory()->create();
        $this->patrol($leader, 2);
        BinName::create(['trash_bin_id' => $this->bin->id, 'user_id' => $leader->id, 'name' => '某某某下台',
            'status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai', 'moderation_note' => '涉及政治人物']);

        Livewire::actingAs($leader)->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertSee('某某某下台')
            ->assertSee('涉及政治人物');

        Livewire::actingAs(User::factory()->create())->test('bin-occupation', ['binId' => $this->bin->id])
            ->assertDontSee('某某某下台');
    }

    public function test_checkin_page_includes_occupation_panel(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-xyz']);

        $this->get('/b/clean_box-xyz')->assertOk()->assertSeeLivewire('bin-occupation')->assertSee('佔領榜');
    }
}
