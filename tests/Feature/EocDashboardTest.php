<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use App\Models\WeatherObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EOC dashboard（spec §7.3 / 驗收 §1-1、§11.1）：浮動可收合卡片層，公開唯讀、
 * 統計對 spec §6.3 定義、外部源中斷顯示明確狀態而非壞版面。
 *
 * health footer 已移至後台 DataSourceHealthWidget（見 DataSourceHealthWidgetTest）。
 * /dashboard 路由整合（含首頁疊圖）由 Task 3 收斂，本檔一律走
 * Livewire::test('eoc-dashboard')，不斷言 HTTP 路由。
 */
class EocDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_component_is_publicly_readable(): void
    {
        Livewire::test('eoc-dashboard')->assertOk();
    }

    public function test_dashboard_component_renders_stats(): void
    {
        $user = User::factory()->create();
        Report::factory()->create(['user_id' => $user->id, 'category' => Report::CATEGORY_FLOOD]);

        Livewire::test('eoc-dashboard')
            ->assertSee('事件統計')
            ->assertSee('CWA 即時觀測');
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
        Livewire::test('eoc-dashboard')
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

        $component = Livewire::test('eoc-dashboard');
        $weather = $component->get('weather');
        $this->assertNotNull($weather);
        $this->assertSame(27.6, $weather['temp']);

        $component->assertDontSee('CWA 資料更新中斷');
    }

    public function test_dashboard_url_redirects_to_home(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_home_page_embeds_dashboard_cards(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('CWA 即時觀測')
            ->assertSee('交通與清運資訊彙整')
            ->assertSee('data-trend', false);   // rain canvas 在首頁
    }

    /**
     * 公開首頁 wire:poll 對每位匿名訪客各打一輪 DB 是規模風險（2d final review）：
     * 共用看板資料需在 10s TTL 內對所有訪客收斂成同一份快取，
     * 而非每次渲染各自重算。用 forget 前後對照證明「快取」才是資料新鮮度的閘門。
     */
    public function test_card_data_is_cached_for_anonymous_visitors(): void
    {
        Report::factory()->create(['category' => Report::CATEGORY_FLOOD, 'created_at' => now()]);

        $first = Livewire::test('eoc-dashboard')->get('stats');
        $this->assertSame(1, collect($first)->firstWhere('slug', Report::CATEGORY_FLOOD)['count']);
        $this->assertTrue(Cache::has('hazdar:eoc:cards'));

        // 快取未過期期間新增的回報不應反映在下一次渲染（同一份快取被重用）。
        Report::factory()->count(5)->create(['category' => Report::CATEGORY_FLOOD, 'created_at' => now()]);

        $stillCached = Livewire::test('eoc-dashboard')->get('stats');
        $this->assertSame(1, collect($stillCached)->firstWhere('slug', Report::CATEGORY_FLOOD)['count']);

        // 手動使快取失效（模擬 TTL 到期）後，重新渲染才看到新資料。
        Cache::forget('hazdar:eoc:cards');

        $afterExpiry = Livewire::test('eoc-dashboard')->get('stats');
        $this->assertSame(6, collect($afterExpiry)->firstWhere('slug', Report::CATEGORY_FLOOD)['count']);
    }
}
