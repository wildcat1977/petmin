<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 收容所（data.taipei 每日同步；災時開設狀態以後台手動為準）。
 */
class Shelter extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'geocoded_at',
        'address',
        'capacity',
        'is_open',
        'source',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geocoded_at' => 'datetime',
            'capacity' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    /** 已取得座標、可上圖者 */
    public function scopeMappable($query)
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }
}
