<?php

namespace App\Support;

/**
 * 需求分數的方格：以固定原點做等距圓柱投影，切成邊長 size 公尺的正方格（台北範圍內誤差可忽略）。
 * 取代 geohash6——geohash 偶數精度是 2:1 長方形、尺寸只能 4 倍跳，S2 又沒有可用的 PHP 實作。
 * 格子以 (ix, iy) 整數編號；地圖點擊、色塊、候選點都用同一套換算，前端拿到 origin/size 就能自己畫格。
 */
final class DemandGrid
{
    /** 與 Geo::distanceMeters 同一個地球半徑（6371km）下，每度緯度的公尺數 */
    public const METERS_PER_DEGREE_LAT = 111194.9266;

    private readonly float $metersPerDegreeLng;

    public function __construct(
        public readonly int $size,
        public readonly float $originLat,
        public readonly float $originLng,
    ) {
        $this->metersPerDegreeLng = self::METERS_PER_DEGREE_LAT * cos(deg2rad($originLat));
    }

    public static function fromConfig(): self
    {
        $grid = config('petmin.demand.grid');

        return new self((int) $grid['size_m'], (float) $grid['origin_lat'], (float) $grid['origin_lng']);
    }

    /** @return array{0: float, 1: float} 相對原點的 [x 東向, y 北向] 公尺 */
    public function toMeters(float $lat, float $lng): array
    {
        return [($lng - $this->originLng) * $this->metersPerDegreeLng, ($lat - $this->originLat) * self::METERS_PER_DEGREE_LAT];
    }

    /** @return array{0: int, 1: int} */
    public function cellAt(float $lat, float $lng): array
    {
        [$x, $y] = $this->toMeters($lat, $lng);

        return [(int) floor($x / $this->size), (int) floor($y / $this->size)];
    }

    public function id(int $ix, int $iy): string
    {
        return "{$ix}:{$iy}";
    }

    /** @return array{lat: float, lng: float} */
    public function center(int $ix, int $iy): array
    {
        return [
            'lat' => $this->originLat + ($iy + 0.5) * $this->size / self::METERS_PER_DEGREE_LAT,
            'lng' => $this->originLng + ($ix + 0.5) * $this->size / $this->metersPerDegreeLng,
        ];
    }

    /** @return array{south: float, west: float, north: float, east: float} */
    public function bounds(int $ix, int $iy): array
    {
        return [
            'south' => $this->originLat + $iy * $this->size / self::METERS_PER_DEGREE_LAT,
            'west' => $this->originLng + $ix * $this->size / $this->metersPerDegreeLng,
            'north' => $this->originLat + ($iy + 1) * $this->size / self::METERS_PER_DEGREE_LAT,
            'east' => $this->originLng + ($ix + 1) * $this->size / $this->metersPerDegreeLng,
        ];
    }

    /** 前端畫格用：原點與每格的經緯度跨度 @return array{size_m: int, lat0: float, lng0: float, dlat: float, dlng: float} */
    public function toArray(): array
    {
        return [
            'size_m' => $this->size,
            'lat0' => $this->originLat,
            'lng0' => $this->originLng,
            'dlat' => $this->size / self::METERS_PER_DEGREE_LAT,
            'dlng' => $this->size / $this->metersPerDegreeLng,
        ];
    }
}
