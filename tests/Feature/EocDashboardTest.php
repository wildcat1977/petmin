<?php

namespace Tests\Feature;

use App\Models\ExternalAlert;
use App\Models\Report;
use App\Models\User;
use App\Models\WeatherObservation;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $this->get('/dashboard')->assertOk()->assertSee('HAZDAR');
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

        $this->assertSame(2, collect($stats)->firstWhere('slug', Report::CATEGORY_FLOOD)['count']);
        $this->assertSame(2, collect($stats)->firstWhere('slug', Report::CATEGORY_FLOOD)['delta']);
    }

    public function test_hourly_delta_only_counts_last_hour(): void
    {
        $user = User::factory()->create();
        Report::factory()->create(['user_id' => $user->id, 'category' => Report::CATEGORY_POWER_OUTAGE, 'created_at' => now()->subMinutes(30)]);
        Report::factory()->create(['user_id' => $user->id, 'category' => Report::CATEGORY_POWER_OUTAGE, 'created_at' => now()->subHours(3)]);

        $stats = Livewire::test('eoc-dashboard')->get('stats');

        $this->assertSame(2, collect($stats)->firstWhere('slug', Report::CATEGORY_POWER_OUTAGE)['count']);
        $this->assertSame(1, collect($stats)->firstWhere('slug', Report::CATEGORY_POWER_OUTAGE)['delta']);
    }

    public function test_cwa_outage_shows_degraded_panel_only_when_no_data_at_all(): void
    {
        // 驗收 §1-1：真的一筆觀測都沒有時才顯示「資料更新中斷」
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('CWA 資料更新中斷')
            ->assertSee('尚無成功紀錄');
    }

    public function test_weather_panel_falls_back_to_db_when_cache_is_cold(): void
    {
        // 快取冷啟（部署/cache:clear/Redis 驅逐）但 DB 有觀測 → 顯示數據，不誤報中斷。
        // CWA 每小時發布延遲造成的快取空窗不該讓面板閃「抓不到」。
        WeatherObservation::create([
            'station_id' => '466920',
            'observed_at' => now()->subMinutes(15),
            'temp' => 27.6,
            'rain_1h' => 42.0,
            'wind_speed' => 3.1,
            'visibility' => 8.0,
        ]);
        // 確保沒有 Redis 快取（測試環境 cache=array，本就空）
        Cache::forget('hazdar:cwa:latest');

        $weather = Livewire::test('eoc-dashboard')->get('weather');
        $this->assertNotNull($weather);
        $this->assertSame(27.6, $weather['temp']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('CWA 資料更新中斷');
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
}
