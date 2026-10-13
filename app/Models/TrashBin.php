<?php

namespace App\Models;

use App\Support\Geohash;
use Database\Factories\TrashBinFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * 垃圾桶點位（官方 open data 匯入 + 市民回報新增）。
 * 回報掛在桶上；核准的 exists / missing 回報會更新 status 與 last_verified_at（TrashBinObserver）。
 */
class TrashBin extends Model
{
    /** @use HasFactory<TrashBinFactory> */
    use HasFactory;

    public const KIND_CLEAN_BOX = 'clean_box';

    public const KIND_DOG_BAG_BOX = 'dog_bag_box';

    public const SOURCE_OFFICIAL_CLEAN_BOX = 'official_clean_box';

    public const SOURCE_OFFICIAL_DOG_BAG_BOX = 'official_dog_bag_box';

    public const SOURCE_CROWD = 'crowd';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MISSING = 'missing';

    public const STATUS_UNVERIFIED = 'unverified';

    public const KIND_LABELS = [
        self::KIND_CLEAN_BOX => '行人專用清潔箱',
        self::KIND_DOG_BAG_BOX => '狗便清潔袋箱',
    ];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => '使用中',
        self::STATUS_MISSING => '消失／已撤',
        self::STATUS_UNVERIFIED => '待驗證',
    ];

    protected $fillable = [
        'external_id', 'source', 'kind', 'district', 'address', 'note',
        'latitude', 'longitude', 'geohash7', 'status', 'box_count',
        'last_verified_at', 'reports_count',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'box_count' => 'integer',
            'reports_count' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TrashBin $bin) {
            if ($bin->latitude !== null && $bin->longitude !== null) {
                $bin->geohash7 = Geohash::encode((float) $bin->latitude, (float) $bin->longitude, 7);
            }
        });
        static::saved(fn () => Cache::flush());
        static::deleted(fn () => Cache::flush());
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }
}
