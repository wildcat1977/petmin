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

    /** 100×100 透明底 + 37 列紅線的合成雷達圖 */
    private function fakeRadarPng(): string
    {
        $img = imagecreatetruecolor(100, 100);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imageline($img, 0, 37, 99, 37, imagecolorallocatealpha($img, 255, 0, 0, 0));

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** 找出第一條不透明紅線所在列，找不到回 -1 */
    private function findStripeRow(\GdImage $img): int
    {
        for ($y = 0; $y < imagesy($img); $y++) {
            $rgba = imagecolorsforindex($img, imagecolorat($img, 50, $y));
            if ($rgba['alpha'] < 64 && $rgba['red'] > 128) {
                return $y;
            }
        }

        return -1;
    }

    public function test_image_is_reprojected_and_cached(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response($this->fakeRadarPng(), 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('hazdar:fetch-radar-echo')->assertSuccessful();

        Storage::disk('public')->assertExists('radar/latest.png');

        // 重採樣後仍為同尺寸有效 PNG（bounds 不變、前端不用改）
        $stored = imagecreatefromstring(Storage::disk('public')->get('radar/latest.png'));
        $this->assertSame(100, imagesx($stored));
        $this->assertSame(100, imagesy($stored));

        // 驗證重採樣確實執行：紅線應向南（圖面下方）移動至 > 37
        $stripeRow = $this->findStripeRow($stored);
        $this->assertGreaterThan(37, $stripeRow, '重採樣應使紅線南移（未重採樣會停在原列 37）');

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
