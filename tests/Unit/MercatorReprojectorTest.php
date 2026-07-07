<?php

namespace Tests\Unit;

use App\Support\MercatorReprojector;
use PHPUnit\Framework\TestCase;

/**
 * 等經緯 → Web Mercator 逐列重採樣（spec §11.2）。
 * CV1 bounds（17.75~29.25N）下，台灣緯度像素應向南（圖面下方）修約 1~3 列/百列，
 * 圖檔上下緣錨定不動、透明像素保持透明。
 */
class MercatorReprojectorTest extends TestCase
{
    private const LAT_MIN = 17.75;

    private const LAT_MAX = 29.25;

    /** 建 100×100 透明底圖，於指定列畫一條不透明紅線，回傳 PNG bytes */
    private function pngWithStripeAtRow(int $row): string
    {
        $img = imagecreatetruecolor(100, 100);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imageline($img, 0, $row, 99, $row, imagecolorallocatealpha($img, 255, 0, 0, 0));

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** 找出第一條不透明紅線所在列，找不到回 -1 */
    private function findStripeRow(string $pngBytes): int
    {
        $img = imagecreatefromstring($pngBytes);
        for ($y = 0; $y < imagesy($img); $y++) {
            $rgba = imagecolorsforindex($img, imagecolorat($img, 50, $y));
            if ($rgba['alpha'] < 64 && $rgba['red'] > 128) {
                return $y;
            }
        }

        return -1;
    }

    public function test_mid_latitude_stripe_shifts_south(): void
    {
        // 列 37 ≈ 24.95°N（台北附近）：等經緯直接疊 Mercator 會北偏，重採樣後應向南修 1~3 列
        $out = (new MercatorReprojector)->reproject($this->pngWithStripeAtRow(37), self::LAT_MIN, self::LAT_MAX);

        $found = $this->findStripeRow($out);
        $this->assertGreaterThan(37, $found, '重採樣後紅線應向南（圖面下方）移動');
        $this->assertLessThanOrEqual(40, $found, '位移量級應為 1~3 列（≈20km／百列圖）');
    }

    public function test_edges_are_anchored(): void
    {
        $reprojector = new MercatorReprojector;

        $top = $this->findStripeRow($reprojector->reproject($this->pngWithStripeAtRow(0), self::LAT_MIN, self::LAT_MAX));
        $bottom = $this->findStripeRow($reprojector->reproject($this->pngWithStripeAtRow(99), self::LAT_MIN, self::LAT_MAX));

        $this->assertSame(0, $top);
        $this->assertSame(99, $bottom);
    }

    public function test_dimensions_and_transparency_preserved(): void
    {
        $out = imagecreatefromstring(
            (new MercatorReprojector)->reproject($this->pngWithStripeAtRow(37), self::LAT_MIN, self::LAT_MAX)
        );

        $this->assertSame(100, imagesx($out));
        $this->assertSame(100, imagesy($out));

        $rgba = imagecolorsforindex($out, imagecolorat($out, 50, 90));
        $this->assertSame(127, $rgba['alpha'], '未著色區域應保持全透明');
    }

    public function test_invalid_bytes_throw(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MercatorReprojector)->reproject('not a png', self::LAT_MIN, self::LAT_MAX);
    }
}
