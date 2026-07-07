<?php

namespace App\Models;

use App\Jobs\EvaluateCorroboration;
use App\Support\Geohash;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /** 內容適切性審核狀態（照片/內容是否可公開；與災情核實正交） */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** 災情分類 */
    public const CATEGORY_FLOOD = 'flood';                          // 淹水

    public const CATEGORY_ROAD_BLOCKED = 'road_blocked';            // 道路中斷

    public const CATEGORY_POWER_OUTAGE = 'power_outage';            // 停電

    public const CATEGORY_STRUCTURAL_DAMAGE = 'structural_damage';  // 結構受損

    /** 災情核實層級（由佐證機制或官方人工升級） */
    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_CORROBORATED = 'corroborated';

    public const VERIFICATION_OFFICIAL = 'official';

    /** 預設座標（未提供位置授權時使用） */
    public const DEFAULT_LATITUDE = 25.0330000;

    public const DEFAULT_LONGITUDE = 121.5654000;

    public const CATEGORIES = [
        self::CATEGORY_FLOOD,
        self::CATEGORY_ROAD_BLOCKED,
        self::CATEGORY_POWER_OUTAGE,
        self::CATEGORY_STRUCTURAL_DAMAGE,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_FLOOD => '淹水',
        self::CATEGORY_ROAD_BLOCKED => '道路中斷',
        self::CATEGORY_POWER_OUTAGE => '停電',
        self::CATEGORY_STRUCTURAL_DAMAGE => '結構受損',
    ];

    public const VERIFICATION_LEVELS = [
        self::VERIFICATION_UNVERIFIED,
        self::VERIFICATION_CORROBORATED,
        self::VERIFICATION_OFFICIAL,
    ];

    public const VERIFICATION_LABELS = [
        self::VERIFICATION_UNVERIFIED => '未核實',
        self::VERIFICATION_CORROBORATED => '多方佐證',
        self::VERIFICATION_OFFICIAL => '官方核實',
    ];

    /** 拒絕原因列表（key 儲存於 DB，value 顯示用） */
    public const REJECTION_REASONS = [
        'ai_auto' => 'AI 自動審核攔截（圖片不符回報規範）',
        'duplicate' => '重複回報（鄰近已有相同回報）',
        'outdated' => '資訊過時（時間過長）',
        'invalid_image' => '照片不符（無法對應所選災情分類）',
        'wrong_location' => '位置異常（座標不合理）',
        'insufficient' => '資料過少無法判讀，煩請補充地點資訊或照片後再次回報',
        'spam' => '不實／惡意回報',
        'other' => '其他',
    ];

    /**
     * 地圖上可顯示的狀態。災時「可能有事」的資訊價值大於等核實，
     * 因此 pending 也上圖（unverified 視覺層級），僅內容審核退件隱藏。
     */
    public const VISIBLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'user_id',
        'category',
        'extra_value',
        'latitude',
        'longitude',
        'geohash7',
        'address',
        'image_path',
        'description',
        'status',
        'verification_level',
        'resolved_at',
        'notified_at',
        'rejection_reason',
        'reviewed_at',
        'first_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'extra_value' => 'integer',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'first_reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 同一使用者的所有回報（自我關聯，供 withCount 使用） */
    public function userReports(): HasMany
    {
        return $this->hasMany(static::class, 'user_id', 'user_id');
    }

    protected static function booted(): void
    {
        // geohash7 一律由 lat/lng 推導（precision 7 ≈ 150m 網格），佐證與聚合用
        static::creating(function (Report $report) {
            if ($report->latitude !== null && $report->longitude !== null) {
                $report->geohash7 = Geohash::encode((float) $report->latitude, (float) $report->longitude, 7);
            }
        });

        // 寫入後觸發佐證判定（deterministic SQL，queue 執行不阻塞寫入路徑）
        static::created(function (Report $report) {
            if ($report->geohash7 && in_array($report->status, self::VISIBLE_STATUSES, true)) {
                EvaluateCorroboration::dispatch($report->geohash7, $report->category)->afterCommit();
            }
        });

        // 狀態從 pending 改為其他時，自動記錄審核時間
        static::updating(function (Report $report) {
            if ($report->isDirty('status') && $report->status !== self::STATUS_PENDING) {
                $report->reviewed_at = now();
                // 首次審核時間只寫入一次，後續重新審核不覆蓋
                if (is_null($report->first_reviewed_at)) {
                    $report->first_reviewed_at = $report->reviewed_at;
                }
            }
        });

        static::saved(function () {
            Cache::forget('report_list.all_coords');
            Cache::forget('report_list.district_stats');
        });
        static::deleted(function () {
            Cache::forget('report_list.all_coords');
            Cache::forget('report_list.district_stats');
        });
    }

    /** 內容審核未退件者皆可上圖 */
    public function scopeVisible($query)
    {
        return $query->whereIn('status', self::VISIBLE_STATUSES);
    }

    /** 官方尚未標記排除的災情 */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /** 照片僅在內容審核通過後公開（spec §6.1-4） */
    public function publicImageUrl(): ?string
    {
        if ($this->image_path && $this->status === self::STATUS_APPROVED) {
            return asset('storage/'.$this->image_path);
        }

        return null;
    }
}
