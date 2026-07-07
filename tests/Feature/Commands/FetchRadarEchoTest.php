<?php

namespace Tests\Feature\Commands;

use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 雷達回波拉取（spec §5、§11.2）：落地前重採樣為 Mercator 對齊、尺寸不變；
 * 非圖片回應必須亮紅燈且不落地（驗收 §1-5）。
 */
class FetchRadarEchoTest extends TestCase
{
    use RefreshDatabase;

    /** 60×60 透明底 + 20 列紅線的合成雷達圖 */
    private function fakeRadarPng(): string
    {
        $img = imagecreatetruecolor(60, 60);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imageline($img, 0, 20, 59, 20, imagecolorallocatealpha($img, 255, 0, 0, 0));

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_image_is_reprojected_and_cached(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response($this->fakeRadarPng(), 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('hazdar:fetch-radar-echo')->assertSuccessful();

        Storage::disk('public')->assertExists('radar/latest.png');

        // 重採樣後仍為同尺寸有效 PNG（bounds 不變、前端不用改）
        $stored = imagecreatefromstring(Storage::disk('public')->get('radar/latest.png'));
        $this->assertSame(60, imagesx($stored));
        $this->assertSame(60, imagesy($stored));

        $this->assertNotNull(Cache::get('hazdar:radar:meta'));
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('radar_echo')['status']);
    }

    public function test_non_image_response_degrades_health(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('hazdar:fetch-radar-echo')->assertFailed();

        Storage::disk('public')->assertMissing('radar/latest.png');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('radar_echo')['status']);
    }
}
