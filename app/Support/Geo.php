<?php

namespace App\Support;

/** 無 PostGIS：Haversine 距離 + 半徑 bbox，幾千筆點位足夠。 */
final class Geo
{
    private const EARTH_RADIUS_M = 6371000.0;

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * 以點為中心、半徑 N 公尺的經緯度 bbox（先用索引縮小範圍，再用 Haversine 精算）。
     *
     * @return array{south: float, west: float, north: float, east: float}
     */
    public static function bbox(float $lat, float $lng, float $radiusMeters): array
    {
        $dLat = rad2deg($radiusMeters / self::EARTH_RADIUS_M);
        $dLng = rad2deg($radiusMeters / (self::EARTH_RADIUS_M * cos(deg2rad($lat))));

        return ['south' => $lat - $dLat, 'west' => $lng - $dLng, 'north' => $lat + $dLat, 'east' => $lng + $dLng];
    }
}
