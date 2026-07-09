<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 登入引導 modal 的個資蒐集告知聲明（spec §12.16）。
 *
 * 與 §11.3 免責彈窗（DisclaimerTest）不同：此為個資法蒐集/處理/利用告知，
 * 對象是 OAuth 登入者。同意方式為「登入即同意」的被動同意，故登入鍵
 * 必須維持可直接觸發、不得因聲明而多出必填閘門。
 */
class AuthOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_statement_rendered_when_onboarding_opens(): void
    {
        Livewire::test('auth-onboarding')
            ->dispatch('open-auth-onboarding')
            ->assertSee('個人資料蒐集、處理及利用告知聲明')
            ->assertSee('個人資料保護法')
            // 五個重編後的條目全在 DOM（面板恆存、僅 Alpine 隱藏）
            ->assertSee('本網站蒐集您個人資料的目的在於')
            ->assertSee('不提供之影響');
    }

    public function test_statement_absent_before_onboarding_opens(): void
    {
        // 未開啟時整個 modal（含聲明）不渲染，避免對每個訪客送出法律長文
        Livewire::test('auth-onboarding')
            ->assertDontSee('個人資料蒐集、處理及利用告知聲明');
    }

    public function test_login_stays_directly_available_no_consent_gate(): void
    {
        // 被動同意：聲明存在，但 LINE / Google 登入鍵仍直接可按，無勾選/必讀閘門
        Livewire::test('auth-onboarding')
            ->dispatch('open-auth-onboarding')
            ->assertSee(route('auth.redirect', 'line'), false)
            ->assertSee(route('auth.redirect', 'google'), false);
    }
}
