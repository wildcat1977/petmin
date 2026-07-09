<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 共用 head nav 的「登入」鈕（PM 回饋）：原本直連 Google OAuth，會跳過 LINE/Google
 * 選擇與個資蒐集告知聲明（spec §12.16）。改為跟「立即回報」guest 流程一致，
 * 觸發 open-auth-onboarding 開啟登入引導 modal；modal 需在該頁面確實掛載才吃得到事件。
 */
class NavLoginTriggersOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{string}> */
    public static function guestPages(): array
    {
        // /profile 需登入（auth middleware），guest 會被 302 導走，不適合測共用 header 的 guest 分支
        return [
            ['/'],
            ['/reports'],
            ['/news'],
            ['/contact'],
            ['/info'], // city-info：先前未掛載 auth-onboarding 的頁面，本次一併補上
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_nav_login_button_dispatches_onboarding_event_not_direct_oauth_link(string $path): void
    {
        $html = $this->get($path)->getContent();

        $this->assertStringContainsString("window.Livewire.dispatch('open-auth-onboarding')", $html);
        // 舊行為：<a href="...auth/google/redirect">登入</a> 直接跳轉，不應再出現
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*auth\/google\/redirect[^>]*>\s*登入\s*<\/a>/', $html);
    }

    #[DataProvider('guestPages')]
    public function test_auth_onboarding_component_is_mounted_on_page(string $path): void
    {
        $html = $this->get($path)->getContent();

        $this->assertStringContainsString('auth-onboarding', $html);
    }
}
