<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 「好遠！」匿名訊號（/find 頁按下），座標是需求網格的格中心，見 create_far_signals_table migration。 */
class FarSignal extends Model
{
    public const MODE_WALKING = 'walking';

    /** 步行路網失敗時以直線 × 係數估算 */
    public const MODE_ESTIMATED = 'estimated';

    protected $fillable = [
        'user_id', 'cell', 'latitude', 'longitude', 'district',
        'nearest_bin_id', 'nearest_distance_m', 'distance_mode', 'ip_hash', 'signal_date',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'nearest_distance_m' => 'integer',
            // signal_date 刻意不 cast 成 date：SQLite 會存成「Y-m-d 00:00:00」，firstOrCreate 用字串比對會找不到而撞 unique
        ];
    }

    public function nearestBin(): BelongsTo
    {
        return $this->belongsTo(TrashBin::class, 'nearest_bin_id');
    }
}
