<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 地圖三態 pin + 三態篩選（spec §12.3/§12.8）。
 * pin 視覺屬 JS，由瀏覽器驗收；此處守 payload 契約與三態 chip 是否渲染。
 */
class MapMarkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_payload_carries_emoji_for_pins(): void
    {
        ReportCategory::create([
            'slug' => 'test_cat', 'name' => '測試類', 'emoji' => '🧪',
            'color' => '#abcdef', 'sort_order' => 99, 'is_active' => true,
        ]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('"emoji"', false)   // categoryMeta payload 供 pinIcon 使用
            ->assertSee('🧪', false);
    }

    public function test_three_status_filter_chips_render(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('data-map-chip="reported"', false)
            ->assertSee('data-map-chip="notified"', false)
            ->assertSee('data-map-chip="resolved"', false);
    }

    /**
     * 需求熱力是提案的核心產出：一進站就要看到，不能藏在 chip 裡等人發現；
     * 熱力開著卻看不出能點，所以要附「點紅色區塊看為什麼」的提示。
     */
    public function test_demand_heat_is_on_by_default_with_click_hint(): void
    {
        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->assertOk()->getContent();

        preg_match_all('/<button[^>]*data-map-chip="demand"[^>]*>/', $html, $chips);
        $this->assertCount(2, $chips[0]); // 桌機 chip + 手機圖層面板
        foreach ($chips[0] as $chip) {
            $this->assertStringContainsString('chip-on', $chip);
        }
        $this->assertStringContainsString('點紅色區塊，看為什麼需要桶', $html);
    }
}
