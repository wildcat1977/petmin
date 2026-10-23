<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 巡桶打卡的桶況三類改成口語名稱並排在「這裡需要垃圾桶」之後：垃圾桶壞了 → 臭臭的 → 垃圾桶滿了！
 * 只改仍是原始名稱的列：後台若已手動改過名稱就不覆蓋。slug 不變（程式依賴 full）。
 */
return new class extends Migration
{
    /** slug => [舊名稱, 新名稱, 新排序] */
    private const CHANGES = [
        'broken' => ['桶壞了', '垃圾桶壞了', 12],
        'smelly' => ['有異味', '臭臭的', 14],
        'full' => ['桶滿了', '垃圾桶滿了！', 16],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $slug => [$old, $new, $order]) {
            DB::table('report_categories')->where('slug', $slug)->where('name', $old)
                ->update(['name' => $new, 'sort_order' => $order, 'updated_at' => now()]);
        }
        Cache::forget('petmin:report-categories:v2');
    }

    public function down(): void
    {
        $orders = ['broken' => 22, 'smelly' => 24, 'full' => 20];
        foreach (self::CHANGES as $slug => [$old, $new]) {
            DB::table('report_categories')->where('slug', $slug)->where('name', $new)
                ->update(['name' => $old, 'sort_order' => $orders[$slug], 'updated_at' => now()]);
        }
        Cache::forget('petmin:report-categories:v2');
    }
};
