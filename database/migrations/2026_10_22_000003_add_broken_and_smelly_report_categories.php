<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 巡桶打卡的桶況回報再加兩類：壞了、有異味，排在「桶滿了」後面。
 * 後台若已手動建過同 slug 就略過（insertOrIgnore），不覆蓋後台改過的名稱或排序。
 * 這兩類目前只是紀錄，不影響需求分數與桶狀態。
 */
return new class extends Migration
{
    private const SLUGS = ['broken', 'smelly'];

    public function up(): void
    {
        $now = now();
        DB::table('report_categories')->insertOrIgnore([
            ['slug' => 'broken', 'name' => '桶壞了', 'emoji' => '🔧', 'color' => '#94a3b8', 'sort_order' => 22, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'smelly', 'name' => '有異味', 'emoji' => '🦨', 'color' => '#84cc16', 'sort_order' => 24, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        // 分類清單有快取（ReportCategory::CACHE_KEY）；只清這個鍵，不用 Cache::flush（正式機多站共用快取）
        Cache::forget('petmin:report-categories:v2');
    }

    public function down(): void
    {
        DB::table('report_categories')->whereIn('slug', self::SLUGS)->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('reports')->whereColumn('reports.category', 'report_categories.slug')
        )->delete();
        Cache::forget('petmin:report-categories:v2');
    }
};
