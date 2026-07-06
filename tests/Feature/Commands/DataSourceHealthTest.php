<?php

namespace Tests\Feature\Commands;

use App\Services\DataSourceHealth;
use App\Services\DataSourceHealth as Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 健康狀態燈號（驗收 §1-5）：黃=延遲、紅=中斷，從未成功=紅。
 * dashboard footer 據此顯示各外部資料源狀態。
 */
class DataSourceHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_never_fetched_source_is_down(): void
    {
        $this->assertSame(Health::STATUS_DOWN, app(Health::class)->status('ncdr_alerts')['status']);
    }

    public function test_status_degrades_with_age(): void
    {
        $health = app(DataSourceHealth::class);
        $health->recordSuccess('ncdr_alerts'); // interval 5 min

        $this->assertSame(Health::STATUS_OK, $health->status('ncdr_alerts')['status']);

        $this->travel(15)->minutes(); // 2×5 < 15 ≤ 6×5 → 延遲
        $this->assertSame(Health::STATUS_DELAYED, $health->status('ncdr_alerts')['status']);

        $this->travel(30)->minutes(); // 共 45 分 > 6×5 → 中斷
        $this->assertSame(Health::STATUS_DOWN, $health->status('ncdr_alerts')['status']);
    }

    public function test_success_clears_previous_error(): void
    {
        $health = app(DataSourceHealth::class);
        $health->recordFailure('ncdr_alerts', 'timeout');
        $health->recordSuccess('ncdr_alerts');

        $status = $health->status('ncdr_alerts');
        $this->assertSame(Health::STATUS_OK, $status['status']);
        $this->assertNull($status['last_error']);
    }

    public function test_radar_echo_fetch_stores_image_and_reports_health(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('civicshield:fetch-radar-echo')->assertSuccessful();

        Storage::disk('public')->assertExists('radar/latest.png');
        $this->assertSame(Health::STATUS_OK, app(Health::class)->status('radar_echo')['status']);
    }

    public function test_radar_echo_rejects_non_image_response(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response('<html>error page</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('civicshield:fetch-radar-echo')->assertFailed();

        // 來源回錯誤頁不能覆寫掉最後一張好圖
        Storage::disk('public')->assertMissing('radar/latest.png');
        $this->assertSame(Health::STATUS_DOWN, app(Health::class)->status('radar_echo')['status']);
    }
}
