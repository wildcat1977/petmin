<?php

namespace Tests\Feature;

use App\Models\ExternalAlert;
use App\Models\InfoLink;
use App\Models\PumpStation;
use App\Models\TransitAlert;
use App\Support\CityInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityInfoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_normal_state_when_no_data(): void
    {
        $this->get('/info')->assertOk()->assertSee('正常上班上課');
    }

    public function test_page_shows_active_closure_and_running_pumps(): void
    {
        ExternalAlert::create([
            'source' => 'DGPA', 'cap_id' => 'C1', 'alert_type' => '停班停課',
            'headline' => '臺北市明天停止上班、停止上課', 'covers_taipei' => true,
            'effective_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        PumpStation::factory()->create(['name' => '玉成', 'pumb_status' => '運轉中']);

        $this->get('/info')->assertOk()
            ->assertSee('停止上班')
            ->assertSee('玉成');
    }

    public function test_page_shows_transit_status(): void
    {
        TransitAlert::create(['system' => 'metro', 'scope_name' => '文湖線', 'status' => 0, 'title' => '停駛']);
        $this->get('/info')->assertOk()->assertSee('捷運')->assertSee('停駛');
    }

    public function test_metro_overall_status_prioritises_adjustment_over_normal(): void
    {
        // 混合狀態：一線正常(1)、一線調整(2)、無停駛 → 整體應為調整(2)，
        // 不可被舊 min() 誤判為正常(1) 而在事故中隱藏調整。
        TransitAlert::create(['system' => 'metro', 'scope_name' => '板南線', 'status' => 1, 'title' => '正常']);
        TransitAlert::create(['system' => 'metro', 'scope_name' => '文湖線', 'status' => 2, 'title' => '調整']);

        $this->assertSame(2, CityInfo::metroOverallStatus());
    }

    public function test_metro_overall_status_suspension_wins(): void
    {
        // 任一線停駛(0) → 整體停駛(0)，優先於調整(2) 與正常(1)。
        TransitAlert::create(['system' => 'metro', 'scope_name' => '板南線', 'status' => 1, 'title' => '正常']);
        TransitAlert::create(['system' => 'metro', 'scope_name' => '文湖線', 'status' => 2, 'title' => '調整']);
        TransitAlert::create(['system' => 'metro', 'scope_name' => '淡水信義線', 'status' => 0, 'title' => '停駛']);

        $this->assertSame(0, CityInfo::metroOverallStatus());
    }

    public function test_manual_sanitation_filters_inactive_and_orders_by_sort_order(): void
    {
        // migration 已預先灌入一筆官方公告（清運與環保局公告），先清空以隔離本測試的固定資料。
        InfoLink::query()->delete();

        // 停用連結不應出現；啟用連結需依 sort_order 由小到大排序，不能依建立順序。
        InfoLink::factory()->create([
            'section' => InfoLink::SECTION_SANITATION, 'label' => '停用連結',
            'is_active' => false, 'sort_order' => 0,
        ]);
        InfoLink::factory()->create([
            'section' => InfoLink::SECTION_SANITATION, 'label' => '第二筆',
            'is_active' => true, 'sort_order' => 2,
        ]);
        InfoLink::factory()->create([
            'section' => InfoLink::SECTION_SANITATION, 'label' => '第一筆',
            'is_active' => true, 'sort_order' => 1,
        ]);

        $this->assertSame(['第一筆', '第二筆'], CityInfo::manualSanitation()->pluck('label')->all());
    }
}
