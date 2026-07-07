<?php

namespace Tests\Feature;

use App\Models\PromoCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 圖卡輪播 banner（spec §12.5）：啟用圖卡於首頁交通清運卡下方輪播；
 * 下架圖卡不外洩；無啟用圖卡則不渲染 banner。
 */
class PromoBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_promo_card_shows_and_inactive_hidden(): void
    {
        PromoCard::create(['title' => '防災三步驟', 'image_path' => 'cards/a.png', 'group' => 'shen', 'is_active' => true]);
        PromoCard::create(['title' => '下架卡', 'image_path' => 'cards/c.png', 'group' => 'shen', 'is_active' => false]);

        Livewire::test('eoc-dashboard')
            ->assertSee('data-promo-banner', false)
            ->assertSee('cards/a.png', false)
            ->assertDontSee('cards/c.png', false);
    }

    public function test_no_banner_when_no_active_cards(): void
    {
        Livewire::test('eoc-dashboard')->assertDontSee('data-promo-banner', false);
    }
}
