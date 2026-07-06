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
            'capacity' => 'integer',
            'is_open' => 'boolean',
        ];
    }
}
