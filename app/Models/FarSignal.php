<?php

namespace App\Models;

use App\Support\Geohash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 「好遠！」匿名訊號（/find 頁按下），見 create_far_signals_table migration。 */
class FarSignal extends Model
{
    public const MODE_WALKING = 'walking';

    public const MODE_STRAIGHT = 'straight';

    protected $fillable = [
        'user_id', 'latitude', 'longitude', 'district',
        'nearest_bin_id', 'nearest_distance_m', 'distance_mode', 'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'nearest_distance_m' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (FarSignal $signal) {
            $signal->geohash7 = Geohash::encode((float) $signal->latitude, (float) $signal->longitude, 7);
        });
    }

    public function nearestBin(): BelongsTo
    {
        return $this->belongsTo(TrashBin::class, 'nearest_bin_id');
    }
}
