<?php

namespace App\Models;

use Database\Factories\InfoLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 交通與清運資訊彙整看板（純官方於 Filament 手動維護，取代物資看板，spec §11.5）。
 */
class InfoLink extends Model
{
    /** @use HasFactory<InfoLinkFactory> */
    use HasFactory;

    /** 分區 */
    public const SECTION_TRANSIT = 'transit';

    public const SECTION_WATER_GATE = 'water_gate';

    public const SECTION_SANITATION = 'sanitation';

    public const SECTION_SHELTER = 'shelter';

    public const SECTION_LABELS = [
        self::SECTION_TRANSIT => '大眾交通',
        self::SECTION_WATER_GATE => '水門與抽水站',
        self::SECTION_SANITATION => '清運與環保局',
        self::SECTION_SHELTER => '避難收容所',
    ];

    protected $fillable = [
        'section',
        'label',
        'url',
        'status_text',
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
