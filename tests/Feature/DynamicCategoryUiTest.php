<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 前端分類 UI 全面 DB 驅動（spec §11.7 驗收 4/5）：
 * 新分類即時出現在 chips/表單/data-categories；停用即時消失於新回報入口。
 */
class DynamicCategoryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_six_seeded_category_chips(): void
    {
        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->getContent();

        foreach (['flood', 'water_outage', 'falling_object'] as $slug) {
            $this->assertStringContainsString('data-map-chip="'.$slug.'"', $html);
        }
        $this->assertStringContainsString('停水', $html);
        $this->assertStringContainsString('大型物品掉落', $html);
        $this->assertStringContainsString('data-categories', $html);
    }

    public function test_new_category_appears_without_code_change(): void
    {
        ReportCategory::create([
            'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
            'color' => '#22c55e', 'sort_order' => 70, 'is_active' => true,
        ]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertSee('data-map-chip="landslide"', false)
            ->assertSee('土石流');
    }

    public function test_deactivated_category_chip_disappears_but_meta_retains_it(): void
    {
        ReportCategory::where('slug', 'flood')->first()->update(['is_active' => false]);

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->getContent();

        $this->assertStringNotContainsString('data-map-chip="flood"', $html);
        // data-categories 仍含 flood（既有點位要能上色/顯示標籤）
        $this->assertMatchesRegularExpression('/data-categories=\'[^\']*flood/', $html);
    }
}
