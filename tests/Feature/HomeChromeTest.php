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

    public function test_footer_shows_published_news_counts_and_hides_removed_links(): void
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
            ->assertDontSee('災情通報')                 // 該分類無已發佈 → 不列
            ->assertSee(route('news'), false)
            ->assertDontSee(route('reports'), false)      // 回報清單隱藏
            ->assertDontSee(route('transparency'), false) // 透明度報告隱藏
            ->assertDontSee(route('stats'), false);       // 統計地圖隱藏
    }
}
