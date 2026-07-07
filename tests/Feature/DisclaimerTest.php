<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 免責聲明（spec §11.3）：進站彈窗掛全站 layout（sessionStorage 每 session 一次，
 * 顯示邏輯在前端）；回報彈窗模式由 config 控制（every|session|off）。
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
            ->assertSee('hazdar:disclaimer:entry', false);
    }
}
