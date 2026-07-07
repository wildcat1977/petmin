<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首頁 chrome（spec §12.1/12.2/12.4/12.6）：配色 #002923、banner、
 * 頂帶 nav、底帶跑馬燈與 footer 分類新聞篇數。
 */
class HomeChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_uses_new_background_color(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('#002923', false)
            ->assertDontSee('bg-[#0d1117]', false);
    }

    public function test_home_shows_banner_image(): void
    {
        $this->get('/')->assertOk()->assertSee('Hazdar-Banner.png', false);
    }

    public function test_top_nav_has_profile_and_contact_for_auth_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'zh-TW')
            ->get('/')
            ->assertOk()
            ->assertSee('我的回報')
            ->assertSee('聯絡管理員');
    }

    public function test_secondary_pages_use_new_background(): void
    {
        $this->get('/news')->assertOk()->assertDontSee('bg-[#0d1117]', false);
        $this->get('/contact')->assertOk()->assertDontSee('bg-[#0d1117]', false);
    }

    public function test_footer_shows_published_news_counts(): void
    {
        // 注意：announcements.content 為 NOT NULL，create 必帶 content（見 NewsPageTest）
        Announcement::create(['title' => '停課通知', 'content' => 'x', 'category' => 'school_closure', 'is_active' => true, 'published_at' => now()->subHour()]);
        Announcement::create(['title' => '居家提醒', 'content' => 'x', 'category' => 'home_safety', 'is_active' => true, 'published_at' => now()->subHour()]);
        // 未設定 published_at：不列入 footer 計數
        Announcement::create(['title' => '草稿災情', 'content' => 'x', 'category' => 'incident', 'is_active' => true]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('停班停課')
            ->assertSee('居家災防')
            ->assertDontSee('災情通報')  // 該分類無已發佈 → 不列
            ->assertSee(route('news'), false);
    }

    /**
     * v0.3 二次回饋 item 6：首頁頂帶與次要頁面共用同一份選單連結（partials.nav-page-links），
     * 不再只有 news——舊版本刻意隱藏這些連結的行為已被此需求取代。
     */
    public function test_top_nav_shares_page_links_with_secondary_pages(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee(route('reports'), false)
            ->assertSee(route('transparency'), false)
            ->assertSee(route('stats'), false);
    }

    /**
     * v0.3 三次回饋：手機寬度塞不下完整 nav（尤其登入後 我的回報/頭像/登出 一起擠進來會
     * 橫向溢出），改漢堡收合面板；桌機 nav（hidden lg:flex）維持不受影響，兩者各自
     * 獨立持有一份連結——用出現次數＝2 佐證漢堡面板不是空殼，桌機版也還在。
     */
    public function test_mobile_has_hamburger_menu_with_full_link_set(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->withHeader('Accept-Language', 'zh-TW')
            ->get('/')
            ->assertOk()
            ->assertSee('mobileNavOpen', false)
            ->getContent();

        // route('news') 另在 footer-links 出現一次（3 次），改用 footer 不會用到的 route('stats') 驗證桌機+手機各一份
        $this->assertSame(2, substr_count($html, route('stats')), '桌機 nav-page-links + 手機漢堡面板應各出現一次');
        $this->assertSame(2, substr_count($html, '聯絡管理員'));
        $this->assertSame(2, substr_count($html, '我的回報'));
    }
}
