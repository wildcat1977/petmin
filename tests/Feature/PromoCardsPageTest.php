<?php

namespace Tests\Feature;

use App\Models\PromoCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 圖卡專區頁（spec §11.6）：依 PromoCard::GROUP_LABELS 鍵序固定分區；
 * 下架卡片不外洩；首頁地圖 icon 連至 /cards。
 */
class PromoCardsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_cards_render_grouped(): void
    {
        PromoCard::create(['title' => '防災三步驟', 'image_path' => 'cards/a.png', 'group' => 'shen', 'is_active' => true]);
        PromoCard::create(['title' => '里長聯絡卡', 'image_path' => 'cards/b.png', 'group' => 'councilor', 'is_active' => true]);
        PromoCard::create(['title' => '下架卡', 'image_path' => 'cards/c.png', 'group' => 'shen', 'is_active' => false]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/cards')
            ->assertOk()
            ->assertSee('沈伯洋圖卡')->assertSee('議員候選人圖卡')
            ->assertSee('防災三步驟')->assertSee('里長聯絡卡')
            ->assertDontSee('下架卡');
    }

    public function test_home_no_longer_links_to_cards_page(): void
    {
        // v0.3：圖卡連結自首頁移除（改首頁 banner，見 Phase 3c）
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertDontSee(route('cards'), false);
    }
}
