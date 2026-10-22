<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 扭蛋卡分享頁：帶這張卡的分享預覽圖與標題，並引導朋友一起出任務；未知卡片 404；其他頁維持站預設預覽圖。 */
class CardSharePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_page_sets_its_own_preview_and_invites_friends(): void
    {
        $this->withoutVite();

        $this->get('/card/honest-pooper')->assertOk()
            ->assertSee('誠實的丟屎人')
            ->assertSee('帶狗狗一起出任務')
            ->assertSee('property="og:title" content="我的狗狗抽到了「誠實的丟屎人」"', false)
            ->assertSee(asset('gacha/share-honest-pooper.jpg'), false);
    }

    public function test_unknown_card_is_404_and_other_pages_keep_default_preview(): void
    {
        $this->withoutVite();

        $this->get('/card/no-such-card')->assertNotFound();
        $this->get('/find')->assertOk()->assertSee(asset('social-image.jpg'), false);
    }
}
