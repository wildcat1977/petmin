<?php

namespace Tests\Unit;

use App\Support\Geohash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeohashTest extends TestCase
{
    /**
     * 佐證分桶與地圖聚合都依賴編碼正確性——用公開參考向量鎖住實作，
     * 避免未來改寫時桶位悄悄漂移導致佐證機制失效。
     */
    public function test_encode_matches_canonical_reference_vector(): void
    {
        $this->assertSame('u4pruydqqvj', Geohash::encode(57.649111, 10.407440, 11));
        $this->assertSame('ezs42', Geohash::encode(42.605, -5.603, 5));
    }

    public function test_precision7_cell_covers_encoded_point(): void
    {
        $lat = 25.0330;
        $lng = 121.5654;
        $hash = Geohash::encode($lat, $lng, 7);

        $this->assertSame(7, strlen($hash));

        $bounds = Geohash::bounds($hash);
        $this->assertGreaterThanOrEqual($bounds['south'], $lat);
        $this->assertLessThanOrEqual($bounds['north'], $lat);
        $this->assertGreaterThanOrEqual($bounds['west'], $lng);
        $this->assertLessThanOrEqual($bounds['east'], $lng);

        // precision 7 網格約 150m：緯度跨度必須遠小於 0.01 度
        $this->assertLessThan(0.01, $bounds['north'] - $bounds['south']);
    }

    public function test_nearby_points_within_same_cell_share_hash(): void
    {
        // 同一網格內兩點（相距數十公尺）必須落在同一桶，佐證才能聚合
        $a = Geohash::encode(25.03300, 121.56540, 7);
        $b = Geohash::encode(25.03310, 121.56545, 7);

        $this->assertSame($a, $b);
    }

    public function test_decode_center_falls_inside_cell(): void
    {
        $center = Geohash::decodeCenter('wsqqqm1');
        $bounds = Geohash::bounds('wsqqqm1');

        $this->assertGreaterThan($bounds['south'], $center['lat']);
        $this->assertLessThan($bounds['north'], $center['lat']);
        $this->assertGreaterThan($bounds['west'], $center['lng']);
        $this->assertLessThan($bounds['east'], $center['lng']);
    }

    public function test_invalid_precision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Geohash::encode(25.0, 121.5, 0);
    }
}
