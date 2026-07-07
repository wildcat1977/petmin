<?php

namespace App\Models;

use Database\Factories\PromoCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 圖卡專區（spec §11.6）：沈伯洋／議員候選人宣傳圖卡，純由 Filament 後台手動維護。
 */
class PromoCard extends Model
{
    /** @use HasFactory<PromoCardFactory> */
    use HasFactory;

    /** 分區 */
    public const GROUP_SHEN = 'shen';

    public const GROUP_COUNCILOR = 'councilor';

    public const GROUP_LABELS = [
        self::GROUP_SHEN => '沈伯洋圖卡',
        self::GROUP_COUNCILOR => '議員候選人圖卡',
    ];

    protected $fillable = [
        'title',
        'image_path',
        'group',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
