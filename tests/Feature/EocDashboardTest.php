<?php

namespace Tests\Feature;

use App\Models\DashboardResource;
use App\Models\ExternalAlert;
use App\Models\Report;
use App\Models\User;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EOC dashboard（spec §7.3 / 驗收 §1-1、§1-5）：
 * 公開唯讀、統計對 spec §6.3 定義、外部源中斷顯示明確狀態而非壞版面、
 * footer 為真實健康指標。
 */
class EocDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_publicly_readable(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('CIVICSHIELD');
    }

    public function test_category_stats_count_last_24h_excluding_rejected_only(): void
    {
        $user = User::factory()->create();
        $base = ['user_id' => $user->id, 'category' => Report::CATEGORY_FLOOD];

        Report::factory()->create([...$base, 'status' => Report::STATUS_PENDING]);   // unverified 也計入
        Report::factory()->create([...$base, 'status' => Report::STATUS_APPROVED]);
        Report::factory()->create([...$base, 'status' => Report::STATUS_REJECTED]);  // 退件排除
        Report::factory()->create([...$base, 'created_at' => now()->subHours(30)]);  // 24h 窗外

        $stats = Livewire::test('eoc-dashboard')->get('stats');

        $this->assertSame(2, $stats[Report::CATEGORY_FLOOD]['count']);
        $this->assertSame(2, $stats[Report::CATEGORY_FLOOD]['delta']);
    }

    public function test_hourly_delta_only_counts_last_hour(): void
    {
        $user = User::factory()->create();
        Report::factory()->create(['user_id' => $user->id, 'category' => Report::CATEGORY_POWER_OUTAGE, 'created_at' => now()->subMinutes(30)]);
        Report::factory()->create(['user_id' => $user->id, 'category' => Report::CATEGORY_POWER_OUTAGE, 'created_at' => now()->subHours(3)]);

        $stats = Livewire::test('eoc-dashboard')->get('stats');

        $this->assertSame(2, $stats[Report::CATEGORY_POWER_OUTAGE]['count']);
        $this->assertSame(1, $stats[Report::CATEGORY_POWER_OUTAGE]['delta']);
    }

    public function test_cwa_outage_shows_degraded_panel_not_broken_layout(): void
    {
        // 驗收 §1-1：外部 API 全掛時 dashboard 顯示「資料更新中斷 + 最後更新時間」
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('CWA 資料更新中斷')
            ->assertSee('尚無成功紀錄');
    }

    public function test_footer_shows_real_health_lights_for_all_sources(): void
    {
        app(DataSourceHealth::class)->recordSuccess('ncdr_alerts');

        $health = Livewire::test('eoc-dashboard')->get('health');

        $this->assertSame(DataSourceHealth::STATUS_OK, $health['ncdr_alerts']['status']);
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $health['cwa_observations']['status']);
        // 四個外部源全都要有狀態燈（禁止默默跳過）
        $this->assertCount(4, $health);
    }

    public function test_typhoon_alert_switches_header_badge(): void
    {
        $this->get('/dashboard')->assertSee('MONITORING')->assertDontSee('TYPHOON-EMERGENCY');

        ExternalAlert::create([
            'source' => 'CWA', 'cap_id' => 'TY1', 'alert_type' => '颱風',
            'headline' => '海上陸上颱風警報', 'covers_taipei' => true,
            'effective_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        $this->get('/dashboard')->assertSee('TYPHOON-EMERGENCY');
    }

    public function test_resource_board_reflects_manual_quantities(): void
    {
        DashboardResource::create(['label' => '沙包', 'current_qty' => 340, 'max_qty' => 500, 'unit' => '組', 'sort_order' => 1]);

        $resources = Livewire::test('eoc-dashboard')->get('resources');

        $this->assertSame('沙包', $resources[0]['label']);
        $this->assertEqualsWithDelta(0.68, $resources[0]['ratio'], 0.001);
    }
}
