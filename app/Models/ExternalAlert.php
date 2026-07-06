<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NCDR 民生示警（CAP）快取，依 cap_id upsert。
 */
class ExternalAlert extends Model
{
    protected $fillable = [
        'source',
        'cap_id',
        'alert_type',
        'headline',
        'severity',
        'areas',
        'covers_taipei',
        'effective_at',
        'expires_at',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'covers_taipei' => 'boolean',
            'raw' => 'array',
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** 跑馬燈條件：未過期 + 涵蓋台北市（spec §5） */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeCoversTaipei($query)
    {
        return $query->where('covers_taipei', true);
    }
}
