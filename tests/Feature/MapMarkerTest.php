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
}
