<?php

namespace App\Models;

use App\Support\Geohash;
use Database\Factories\DemandProxyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** 人流／遛狗 proxy 點位（捷運站、公車站牌、狗活動區、陳情熱點），餵給 DemandScoreService。 */
class DemandProxy extends Model
{
    /** @use HasFactory<DemandProxyFactory> */
    use HasFactory;

    public const KIND_MRT = 'mrt';

    public const KIND_BUS_STOP = 'bus_stop';

    public const KIND_DOG_PARK = 'dog_park';

    public const KIND_PARK = 'park';

    public const KIND_PETITION = 'petition';

    public const KIND_LABELS = [
        self::KIND_MRT => '捷運站',
        self::KIND_BUS_STOP => '公車站牌',
        self::KIND_DOG_PARK => '狗活動區',
        self::KIND_PARK => '公園',
        self::KIND_PETITION => '陳情熱點',
    ];

    protected $fillable = ['kind', 'external_id', 'name', 'district', 'latitude', 'longitude', 'geohash7', 'weight', 'meta'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'weight' => 'float',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DemandProxy $proxy) {
            $proxy->geohash7 = Geohash::encode((float) $proxy->latitude, (float) $proxy->longitude, 7);
        });
    }
}
