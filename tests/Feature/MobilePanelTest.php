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

    public function test_mobile_panel_renders_layer_toggles_and_card_buttons(): void
    {
        Livewire::test('eoc-dashboard')
            ->assertSee('圖層')                                  // 面板圖層區標題（面板專有）
            ->assertSee('狀態')                                  // 面板三態區標題（面板專有）
            ->assertSee('data-map-chip="notified"', false)       // 面板三態 checkbox（本元件僅面板有）
            ->assertSee('data-map-chip="shelters"', false)       // 面板收容所 checkbox
            ->assertSee('card-open-btn', false)                  // 面板卡片按鈕
            // 面板圖層/狀態 checkbox 群組須以 <div wire:ignore> 整組隔離 wire:poll.10s
            // morph，否則 initChips（app.js）切換的 chip-on 勾選狀態每 10s 被還原
            // （3d review Critical）。注意：card-rain 分量本身也有一個
            // `wire:ignore class="mt-2 h-28"`，字串不同，故此斷言只認裸 `<div wire:ignore>`。
            ->assertSee('<div wire:ignore>', false);
    }
}
