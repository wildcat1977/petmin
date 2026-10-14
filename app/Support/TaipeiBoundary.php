<?php

namespace App\Support;

use RuntimeException;

/**
 * 臺北市市界（public/geo/taipei-districts.json 的 12 個行政區多邊形）：點是否在市內、在哪一區。
 * 垃圾桶資料只有臺北市，市界外沒有資料就不能宣稱缺桶，需求分數只算市內的格子。
 */
final class TaipeiBoundary
{
    /** @var list<array{name: string, rings: list<list<array{0: float, 1: float}>>}>|null */
    private static ?array $districts = null;

    public static function contains(float $lat, float $lng): bool
    {
        return self::districtAt($lat, $lng) !== null;
    }

    /** @return list<string> 12 個行政區名 */
    public static function names(): array
    {
        return array_column(self::districts(), 'name');
    }

    public static function districtAt(float $lat, float $lng): ?string
    {
        foreach (self::districts() as $district) {
            foreach ($district['rings'] as $ring) {
                if (self::inRing($lng, $lat, $ring)) {
                    return $district['name'];
                }
            }
        }

        return null;
    }

    /** 射線法（只看外環；行政區沒有挖洞） */
    private static function inRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function districts(): array
    {
        if (self::$districts !== null) {
            return self::$districts;
        }
        $file = public_path('geo/taipei-districts.json');
        $geo = is_readable($file) ? json_decode(file_get_contents($file), true) : null;
        if (! is_array($geo['features'] ?? null)) {
            throw new RuntimeException("讀不到臺北市行政區界：{$file}");
        }

        return self::$districts = array_map(fn (array $f) => [
            'name' => $f['properties']['name'] ?? '',
            'rings' => match ($f['geometry']['type']) {
                'Polygon' => [$f['geometry']['coordinates'][0]],
                'MultiPolygon' => array_map(fn ($polygon) => $polygon[0], $f['geometry']['coordinates']),
                default => [],
            },
        ], $geo['features']);
    }
}
