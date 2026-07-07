<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 最新消息頁（spec §11.4）：published_at 控制上稿、置頂優先、
 * 未發布不外洩；跑馬燈語義（is_active）不受影響。
 */
class NewsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_items_render_in_pinned_then_date_order(): void
    {
        Announcement::create(['title' => '較舊消息', 'content' => 'x', 'is_active' => false, 'category' => 'gov_press', 'published_at' => now()->subDay()]);
        Announcement::create(['title' => '置頂消息', 'content' => 'x', 'is_active' => false, 'category' => 'incident', 'is_pinned' => true, 'published_at' => now()->subDays(3)]);
        Announcement::create(['title' => '最新消息一則', 'content' => 'x', 'is_active' => false, 'category' => 'school_closure', 'published_at' => now()->subHour()]);

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/news')->assertOk()->getContent();

        $this->assertTrue(strpos($html, '置頂消息') < strpos($html, '最新消息一則'));
        $this->assertTrue(strpos($html, '最新消息一則') < strpos($html, '較舊消息'));
        $this->assertStringContainsString('災情通報', $html);   // 分類徽章
        $this->assertStringContainsString('停班停課', $html);
    }

    public function test_unpublished_and_future_items_are_hidden(): void
    {
        Announcement::create(['title' => '草稿不可見', 'content' => 'x', 'is_active' => true]);
        Announcement::create(['title' => '未來排程不可見', 'content' => 'x', 'is_active' => false, 'published_at' => now()->addDay()]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/news')
            ->assertOk()
            ->assertDontSee('草稿不可見')
            ->assertDontSee('未來排程不可見');
    }

    public function test_banner_semantics_unchanged_by_news_fields(): void
    {
        $a = Announcement::create(['title' => 'T', 'content' => '跑馬燈內容', 'is_active' => true, 'published_at' => null]);

        $this->assertTrue(Announcement::active()->get()->contains($a));
        $this->assertFalse(Announcement::published()->get()->contains($a));
    }

    public function test_external_link_renders_when_set(): void
    {
        Announcement::create(['title' => '外連消息', 'content' => 'x', 'is_active' => false, 'category' => 'gov_press', 'link_url' => 'https://www.gov.taipei/example', 'published_at' => now()]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/news')
            ->assertOk()
            ->assertSee('https://www.gov.taipei/example', false);
    }

    public function test_body_html_is_sanitized_against_script_injection(): void
    {
        Announcement::create([
            'title' => '注入測試', 'content' => 'x', 'is_active' => false, 'category' => 'incident',
            'body' => '<p>安全內容</p><script>alert(1)</script>',
            'published_at' => now(),
        ]);

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/news')->assertOk()->getContent();

        $this->assertStringContainsString('安全內容', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
