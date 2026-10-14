<?php

namespace Tests\Unit;

use App\Support\TaipeiBoundary;
use Tests\TestCase;

/** 市界判斷錯了，新北市會被當成「沒有垃圾桶」的熱區，或臺北市邊緣的格子被漏算。 */
class TaipeiBoundaryTest extends TestCase
{
    public function test_known_places_fall_on_the_right_side_of_the_city_line(): void
    {
        $this->assertSame('信義區', TaipeiBoundary::districtAt(25.0330, 121.5654));   // 台北 101
        $this->assertSame('北投區', TaipeiBoundary::districtAt(25.1318, 121.4986));   // 北投站
        $this->assertSame('萬華區', TaipeiBoundary::districtAt(25.0353, 121.4999));   // 龍山寺
        $this->assertFalse(TaipeiBoundary::contains(25.0230, 121.4683));              // 新北・新埔站
        $this->assertFalse(TaipeiBoundary::contains(25.0137, 121.4625));              // 新北・板橋站
        $this->assertFalse(TaipeiBoundary::contains(25.1677, 121.4459));              // 新北・淡水
    }
}
