<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 免責聲明：進站彈窗掛全站 layout（sessionStorage 每 session 一次，
 * 顯示邏輯在前端）；回報彈窗模式由 config 控制（every|session|off），預設 session。
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
            ->assertSee('petmin:disclaimer:entry', false);
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

        // session 模式仍需渲染 markup（已讀與否由前端 sessionStorage 決定）
        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertSee('僅供大眾參考')
            ->assertSee('petmin:disclaimer:report', false);
    }
}
