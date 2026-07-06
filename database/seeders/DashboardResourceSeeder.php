<?php

namespace Database\Seeders;

use App\Models\DashboardResource;
use Illuminate\Database\Seeder;

/**
 * 物資看板初始項目（mockup 右欄；數量之後由官方在 Filament 手動維護）。
 */
class DashboardResourceSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['label' => '沙包 / 防水閘板', 'current_qty' => 340, 'max_qty' => 500, 'unit' => '組', 'sort_order' => 1],
            ['label' => '飲用水', 'current_qty' => 820, 'max_qty' => 1000, 'unit' => '箱', 'sort_order' => 2],
            ['label' => '緊急醫療包', 'current_qty' => 28, 'max_qty' => 120, 'unit' => '份', 'sort_order' => 3],
            ['label' => '認證志工 (在線)', 'current_qty' => 47, 'max_qty' => 80, 'unit' => '人', 'sort_order' => 4],
            ['label' => '發電機', 'current_qty' => 6, 'max_qty' => 20, 'unit' => '台', 'sort_order' => 5],
        ];

        foreach ($items as $item) {
            DashboardResource::firstOrCreate(['label' => $item['label']], $item);
        }
    }
}
