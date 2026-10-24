<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 免責聲明：進站彈窗掛全站 layout，回報彈窗模式由 config 控制（every|session|off），預設 session。
 * 看過一次記在 cookie（兩個彈窗共用）：LINE／FB 內建瀏覽器每次開都是新分頁，sessionStorage 會讓它每次重跳。
 */
class DisclaimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_disclaimer_renders_on_home(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')
            ->get('/')
            ->assertOk()
            ->assertSee('使用前請注意')
            ->assertSee('僅供大眾參考')
            ->assertSee('petmin_disclaimer_v1=1', false);
    }

    public function test_report_form_disclaimer_shows_in_every_mode(): void
    {
        config(['petmin.disclaimer.report_mode' => 'every']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertSee('僅供大眾參考')
            ->assertSee('我知道了，開始回報');
    }

    public function test_report_form_disclaimer_off_mode_renders_nothing(): void
    {
        config(['petmin.disclaimer.report_mode' => 'off']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertDontSee('僅供大眾參考');
    }

    public function test_report_form_disclaimer_session_mode_renders_with_session_flag(): void
    {
        config(['petmin.disclaimer.report_mode' => 'session']);
        $user = User::factory()->create();

        // session 模式仍需渲染 markup（已讀與否由前端讀 cookie 決定）
        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertSee('僅供大眾參考')
            ->assertSee('petmin_disclaimer_v1=1', false);
    }
}
