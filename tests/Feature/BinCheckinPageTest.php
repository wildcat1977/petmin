<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 巡桶打卡頁 /b/{bin}：NFC 貼紙與 QR Code 指向的網址。 */
class BinCheckinPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_page_shows_bin_and_report_button(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-abc123', 'address' => '北平東路口', 'district' => '中正區', 'last_verified_at' => now()->subDays(2)]);

        $this->get('/b/clean_box-abc123?via=nfc')
            ->assertOk()
            ->assertSee('佔領成功')
            ->assertSee('北平東路口')
            ->assertSee('2 天前有人確認還在')
            ->assertSee('回報這個桶的狀況')
            ->assertSee("binId: {$bin->id}", false);
    }

    public function test_official_bin_is_not_reachable_by_database_id(): void
    {
        // 貼紙一律寫官方編號；官方桶不能用各環境不同的自動編號打開，免得指錯桶
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-abc123']);

        $this->get("/b/{$bin->id}")->assertNotFound();
    }

    public function test_crowd_bin_without_official_code_uses_database_id(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => null, 'source' => TrashBin::SOURCE_CROWD, 'address' => '市民新增的桶']);

        $this->get("/b/{$bin->id}")->assertOk()->assertSee('市民新增的桶');
        $this->get('/b/no-such-bin')->assertNotFound();
    }

    public function test_checkin_report_requires_photo_but_normal_report_does_not(): void
    {
        $user = User::factory()->create();
        $bin = TrashBin::factory()->create(['latitude' => 25.0466, 'longitude' => 121.5245]);

        // 打卡頁打開：照片必填
        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form', binId: $bin->id, requirePhoto: true)
            ->assertSet('requirePhoto', true)
            ->assertSee('必填；拍到桶或現場最好')
            ->call('submit')
            ->assertHasErrors(['photo' => 'required']);
        $this->assertSame(0, Report::count());

        // 一般入口打開：維持選填，可以不附照片送出
        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form', binId: $bin->id)
            ->assertSet('requirePhoto', false)
            ->call('submit')
            ->assertHasNoErrors();
        $this->assertSame(1, Report::count());
    }

    public function test_checkin_page_opens_form_with_photo_required(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-xyz']);

        $this->get('/b/clean_box-xyz')->assertOk()->assertSee('requirePhoto: true', false);
    }

    public function test_mission_intro_only_when_arriving_from_sticker(): void
    {
        $this->withoutVite();
        TrashBin::factory()->create(['external_id' => 'clean_box-intro']);

        $this->get('/b/clean_box-intro?via=nfc')->assertOk()->assertSee('今日巡桶任務開始');
        $this->get('/b/clean_box-intro')->assertOk()->assertDontSee('今日巡桶任務開始');
    }

    public function test_dev_login_route_is_not_registered_outside_local(): void
    {
        // 測試環境 APP_ENV=testing：本機專用的一鍵登入不能存在（正式機 production 同理）
        $this->get('/dev/login')->assertNotFound();
        $this->assertGuest();
    }
}
