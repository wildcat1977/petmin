<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 首頁公告彈窗（spec §12.17）：內容單一來源為富文本 body，
 * 彈窗只顯示 strip HTML 後前 30 字摘要，超出則附「詳見最新消息」連往 /news。
 */
class AnnouncementBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_body_is_truncated_to_30_chars_with_news_link(): void
    {
        $body = str_repeat('淹', 50); // 50 字，超過 30
        Announcement::create(['content' => null, 'body' => "<p>{$body}</p>", 'is_active' => true]);

        Livewire::test('announcement-banner')
            ->assertSee(str_repeat('淹', 30))          // 前 30 字有顯示
            ->assertDontSee(str_repeat('淹', 31))       // 第 31 字被截掉
            ->assertSee('詳見最新消息')
            ->assertSee(route('news'), false);
    }

    public function test_short_body_shows_full_text_without_news_link(): void
    {
        Announcement::create(['content' => null, 'body' => '<p>短公告</p>', 'is_active' => true]);

        Livewire::test('announcement-banner')
            ->assertSee('短公告')
            ->assertDontSee('詳見最新消息');
    }

    public function test_html_tags_are_stripped_in_banner_preview(): void
    {
        Announcement::create(['content' => null, 'body' => '<p><strong>粗體</strong>提醒</p>', 'is_active' => true]);

        Livewire::test('announcement-banner')
            ->assertSee('粗體提醒')
            ->assertDontSee('<strong>', false);
    }

    public function test_legacy_content_only_row_falls_back_to_content(): void
    {
        // 舊資料只有 content、沒有 body：彈窗仍需顯示 content（回填前的相容）
        Announcement::create(['content' => '舊版純文字公告', 'body' => null, 'is_active' => true]);

        Livewire::test('announcement-banner')
            ->assertSee('舊版純文字公告');
    }
}
