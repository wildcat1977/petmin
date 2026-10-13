<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首頁 chrome：配色、logo、頂帶 nav、跑馬燈與 footer 分類新聞篇數。
 */
class HomeChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_uses_new_background_color(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('#1c1917', false)
            ->assertDontSee('bg-[#0d1117]', false);
    }

    public function test_home_shows_logo(): void
    {
        $this->get('/')->assertOk()->assertSee('Petmin', false)->assertSee('找回消失的垃圾桶');
    }

    /**
     * 底色 / 卡片色由 config('petmin.theme') 注入 CSS 變數
     * （--color-radar-bg / --color-radar-card），改 env 即可全站換色不必改各處 blade。
     * 驗證 config 值確實輸出到 layout <head> 的覆蓋 style。
     */
    public function test_theme_colors_are_config_driven(): void
    {
        config(['petmin.theme.bg' => '#123456', 'petmin.theme.card' => '#abcdef']);

        $this->get('/')
            ->assertOk()
            ->assertSee('--color-radar-bg: #123456', false)
            ->assertSee('--color-radar-card: #abcdef', false)
            ->assertSee('content="#123456"', false); // theme-color meta 亦跟隨 config
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
        Announcement::create(['title' => '黑客松報名', 'content' => 'x', 'category' => 'event', 'is_active' => true, 'published_at' => now()->subHour()]);
        Announcement::create(['title' => '環保局回覆', 'content' => 'x', 'category' => 'gov_press', 'is_active' => true, 'published_at' => now()->subHour()]);
        // 未設定 published_at：不列入 footer 計數
        Announcement::create(['title' => '草稿', 'content' => 'x', 'category' => 'policy', 'is_active' => true]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('活動')
            ->assertSee('環保局公告')
            ->assertDontSee('政策進度')  // 該分類無已發佈 → 不列
            ->assertSee(route('news'), false);
    }

    /**
     * 首頁頂帶與次要頁面共用同一份選單連結（partials.nav-page-links）。
     */
    public function test_top_nav_shares_page_links_with_secondary_pages(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee(route('reports'), false)
            ->assertDontSee(route('transparency'), false)
            ->assertDontSee(route('stats'), false)
            ->assertSee(route('candidates'), false)
            ->assertSee(route('leaderboard'), false);
    }

    /**
     * 手機寬度塞不下完整 nav，改漢堡收合面板；桌機 nav（hidden lg:flex）維持不受影響，兩者各自
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

        // route('news') 另在 footer-links 出現一次（3 次）。改用只存在於
        // nav-page-links + 手機漢堡面板兩處、別處都不會用到的 route('reports') 驗證。
        $this->assertSame(2, substr_count($html, route('reports')), '桌機 nav-page-links + 手機漢堡面板應各出現一次');
        $this->assertSame(2, substr_count($html, '聯絡管理員'));
        $this->assertSame(2, substr_count($html, '我的回報'));
    }

    /**
     * 次要頁面（profile/reports/news/...）與首頁共用 partials.site-header 單一來源，
     * 兩邊都應顯示 logo 與跑馬燈容器（#site-ticker 由獨立 site-ticker 元件供應，不必掛整個地圖）。
     */
    public function test_secondary_pages_share_home_header_with_logo_and_ticker(): void
    {
        foreach (['/news', '/contact'] as $path) {
            $this->withHeader('Accept-Language', 'zh-TW')->get($path)
                ->assertOk()
                ->assertSee('找回消失的垃圾桶')
                ->assertSee('id="site-ticker"', false);
        }
    }
}
