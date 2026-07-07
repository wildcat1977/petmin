<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 圖卡專區獨立頁已退場（spec §12.5）：/cards 302 轉向首頁；
 * 圖卡改由首頁輪播 banner 呈現（見 PromoBannerTest）。
 */
class PromoCardsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_url_redirects_to_home(): void
    {
        $this->get('/cards')->assertRedirect('/');
    }
}
