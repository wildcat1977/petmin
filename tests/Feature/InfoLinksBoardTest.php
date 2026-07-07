<?php

namespace Tests\Feature;

use App\Models\InfoLink;
use App\Models\Shelter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 交通與清運資訊彙整看板（spec §11.5，取代物資看板）：
 * 分區彙整官方連結、僅顯示啟用連結、避難收容所分區附開設中數量。
 */
class InfoLinksBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_info_links_grouped_with_shelter_count(): void
    {
        Shelter::create(['name' => '收容所A', 'is_open' => true]);
        Shelter::create(['name' => '收容所B', 'is_open' => true]);
        Shelter::create(['name' => '收容所C', 'is_open' => false]);

        Livewire::test('eoc-dashboard')
            ->assertSee('交通與清運資訊彙整')
            ->assertSee('台北捷運')
            ->assertSee('水門開放狀態')
            ->assertSee('開設中 2')
            ->assertDontSee('物資 / 民力調度看板');
    }

    public function test_inactive_link_is_hidden(): void
    {
        InfoLink::where('label', '台北捷運')->first()->update(['is_active' => false]);

        Livewire::test('eoc-dashboard')->assertDontSee('台北捷運');
    }

    public function test_section_order_follows_section_labels_not_sort_order(): void
    {
        // 區塊順序須依 SECTION_LABELS 鍵序結構性固定：sort_order 只排區內連結，
        // 後台 reorderable 跨區拖曳會全域改號，不能因此打亂區塊排列。
        InfoLink::where('section', 'shelter')->first()->update(['sort_order' => 1]);

        $html = Livewire::test('eoc-dashboard')->html();

        $this->assertTrue(strpos($html, '大眾交通') < strpos($html, '避難收容所'));
    }

    public function test_shelter_count_badge_survives_all_shelter_links_deactivated(): void
    {
        // shelter 區塊即使沒有任何啟用連結，開設中 N 徽章仍須常駐（spec §11.5）。
        InfoLink::where('section', 'shelter')->update(['is_active' => false]);
        Shelter::create(['name' => '收容所A', 'is_open' => true]);
        Shelter::create(['name' => '收容所B', 'is_open' => true]);
        Shelter::create(['name' => '收容所C', 'is_open' => true]);

        Livewire::test('eoc-dashboard')
            ->assertSee('避難收容所')
            ->assertSee('開設中 3');
    }
}
