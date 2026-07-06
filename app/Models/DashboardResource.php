<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 物資看板（純官方於 Filament 手動維護，spec §2）。
 */
class DashboardResource extends Model
{
    protected $fillable = [
        'label',
        'current_qty',
        'max_qty',
        'unit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'current_qty' => 'integer',
            'max_qty' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
