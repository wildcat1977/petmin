<?php

namespace App\Support;

use RuntimeException;

/**
 * 等經緯（equirectangular）PNG 逐列重採樣為 Web Mercator 對齊（spec §11.2）。
 *
 * Leaflet ImageOverlay 把圖檔在投影後座標間線性拉伸；CWA 雷達回波原圖是
 * 等經緯投影，直接疊圖會讓台灣緯度像素北偏約 20km。落地前先重採樣校正，
 * bounds 與前端完全不動。
 */
class MercatorReprojector
{
    public function reproject(string $pngBytes, float $latMin, float $latMax): string
    {
        $src = @imagecreatefromstring($pngBytes);
        if ($src === false) {
            throw new RuntimeException('無法解析雷達回波圖檔');
        }

        imagepalettetotruecolor($src);

        $width = imagesx($src);
        $height = imagesy($src);

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));

        $yTop = self::latToMercatorY($latMax);
        $yBottom = self::latToMercatorY($latMin);

        for ($row = 0; $row < $height; $row++) {
            // 輸出列於 Mercator 空間等分 → 反推緯度 → 取等經緯原圖對應列
            $mercY = $yTop - ($row / max($height - 1, 1)) * ($yTop - $yBottom);
            $lat = self::mercatorYToLat($mercY);
            $srcRow = (int) round(($latMax - $lat) / ($latMax - $latMin) * ($height - 1));

            imagecopy($dst, $src, 0, $row, 0, max(0, min($height - 1, $srcRow)), $width, 1);
        }

        ob_start();
        imagepng($dst);
        $out = ob_get_clean();

        return $out;
    }

    private static function latToMercatorY(float $lat): float
    {
        return log(tan(M_PI / 4 + deg2rad($lat) / 2));
    }

    private static function mercatorYToLat(float $y): float
    {
        return rad2deg(2 * atan(exp($y)) - M_PI / 2);
    }
}
