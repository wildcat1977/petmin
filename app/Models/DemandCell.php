<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** geohash6（~600m）網格需求分數快取；由 demand:recompute 整批重算。 */
class DemandCell extends Model
{
    public $timestamps = false;

    protected $fillable = ['geohash6', 'latitude', 'longitude', 'score', 'dumping_score', 'breakdown', 'computed_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'score' => 'float',
            'dumping_score' => 'float',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
