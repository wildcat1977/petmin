<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CWA 測站觀測歷史（供 24H 趨勢圖）；保留 7 天，daily prune。
 */
class WeatherObservation extends Model
{
    protected $fillable = [
        'station_id',
        'observed_at',
        'temp',
        'rain_1h',
        'wind_speed',
        'visibility',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'temp' => 'float',
            'rain_1h' => 'float',
            'wind_speed' => 'float',
            'visibility' => 'float',
            'raw' => 'array',
        ];
    }
}
