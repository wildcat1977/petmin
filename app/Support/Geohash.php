<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 純 PHP Geohash 編碼／解碼（無外部依賴）。
 *
 * precision 7 ≈ 153m × 153m 網格，作為回報佐證（corroboration）
 * 與地圖低 zoom 聚合的分桶單位。
 */
final class Geohash
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    public static function encode(float $latitude, float $longitude, int $precision = 7): string
    {
        if ($precision < 1 || $precision > 12) {
            throw new InvalidArgumentException("Geohash precision must be 1-12, got {$precision}");
        }

        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bit = 0;
        $ch = 0;
        $isLngBit = true;

        while (strlen($hash) < $precision) {
            if ($isLngBit) {
                $mid = ($lngRange[0] + $lngRange[1]) / 2;
                if ($longitude >= $mid) {
                    $ch = ($ch << 1) | 1;
                    $lngRange[0] = $mid;
                } else {
                    $ch <<= 1;
                    $lngRange[1] = $mid;
                }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                if ($latitude >= $mid) {
                    $ch = ($ch << 1) | 1;
                    $latRange[0] = $mid;
                } else {
                    $ch <<= 1;
                    $latRange[1] = $mid;
                }
            }

            $isLngBit = ! $isLngBit;

            if (++$bit === 5) {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }

        return $hash;
    }

    /**
     * 解出網格邊界。
     *
     * @return array{south: float, west: float, north: float, east: float}
     */
    public static function bounds(string $hash): array
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $isLngBit = true;

        foreach (str_split(strtolower($hash)) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new InvalidArgumentException("Invalid geohash character: {$char}");
            }

            for ($bit = 4; $bit >= 0; $bit--) {
                $bitValue = ($index >> $bit) & 1;
                if ($isLngBit) {
                    $mid = ($lngRange[0] + $lngRange[1]) / 2;
                    $lngRange[$bitValue ? 0 : 1] = $mid;
                } else {
                    $mid = ($latRange[0] + $latRange[1]) / 2;
                    $latRange[$bitValue ? 0 : 1] = $mid;
                }
                $isLngBit = ! $isLngBit;
            }
        }

        return [
            'south' => $latRange[0],
            'west' => $lngRange[0],
            'north' => $latRange[1],
            'east' => $lngRange[1],
        ];
    }

    /**
     * 網格中心點。
     *
     * @return array{lat: float, lng: float}
     */
    public static function decodeCenter(string $hash): array
    {
        $b = self::bounds($hash);

        return [
            'lat' => ($b['south'] + $b['north']) / 2,
            'lng' => ($b['west'] + $b['east']) / 2,
        ];
    }
}
