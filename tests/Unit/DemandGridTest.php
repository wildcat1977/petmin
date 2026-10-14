<?php

namespace Tests\Unit;

use App\Support\DemandGrid;
use App\Support\Geo;
use PHPUnit\Framework\TestCase;

/**
 * 需求格是固定原點的方格：地圖點擊、熱力色塊、候選點都靠同一套換算對到同一格，
 * 任何一邊算偏，點下去的明細就會是隔壁格。
 */
class DemandGridTest extends TestCase
{
    private function grid(): DemandGrid
    {
        return new DemandGrid(150, 25.05, 121.5);
    }

    public function test_cells_are_square_with_configured_size(): void
    {
        $b = $this->grid()->bounds(10, -4);

        $height = ($b['north'] - $b['south']) * DemandGrid::METERS_PER_DEGREE_LAT;
        $width = ($b['east'] - $b['west']) * DemandGrid::METERS_PER_DEGREE_LAT * cos(deg2rad(25.05));
        $this->assertEqualsWithDelta(150, $height, 0.01);
        $this->assertEqualsWithDelta(150, $width, 0.01);
    }

    public function test_point_maps_to_the_cell_whose_bounds_contain_it(): void
    {
        $grid = $this->grid();
        foreach ([[25.0330, 121.5654], [25.0500, 121.5000], [24.9661, 121.4183], [25.1340, 121.5033]] as [$lat, $lng]) {
            [$ix, $iy] = $grid->cellAt($lat, $lng);
            $b = $grid->bounds($ix, $iy);
            $this->assertTrue($lat >= $b['south'] && $lat < $b['north'] && $lng >= $b['west'] && $lng < $b['east'], "{$lat},{$lng}");
        }
    }

    public function test_center_round_trips_to_same_cell_and_ids_are_stable(): void
    {
        $grid = $this->grid();
        $center = $grid->center(-7, 13);

        $this->assertSame([-7, 13], $grid->cellAt($center['lat'], $center['lng']));
        $this->assertSame('-7:13', $grid->id(-7, 13));
    }

    public function test_projected_distance_matches_haversine_within_taipei(): void
    {
        $grid = $this->grid();
        [$x1, $y1] = $grid->toMeters(25.0330, 121.5654);
        [$x2, $y2] = $grid->toMeters(25.0478, 121.5170);

        // 台北車站 ↔ 市政府約 5.1km；平面近似誤差要遠小於 150m 格
        $this->assertEqualsWithDelta(Geo::distanceMeters(25.0330, 121.5654, 25.0478, 121.5170), hypot($x2 - $x1, $y2 - $y1), 10);
    }
}
