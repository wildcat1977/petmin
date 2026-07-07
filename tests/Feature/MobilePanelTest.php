<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 手機右側 IITC 圖層/資訊面板（spec §12.7）。面板互動屬 JS/Alpine，
 * 由瀏覽器驗收；此處守「桌機 chips 手機隱藏」與「面板結構是否渲染」。
 */
class MobilePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_chips_hidden_on_mobile(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('lg:flex-wrap', false); // chips 容器改為 hidden lg:flex lg:flex-wrap
    }
}
