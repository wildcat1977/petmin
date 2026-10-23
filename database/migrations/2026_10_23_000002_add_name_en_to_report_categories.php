<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 分類的英文名稱（英文版網站顯示；留空就顯示中文 name）。
 * 分類是後台可增改的資料，翻譯跟著資料走，不放 lang/en.json。內建分類順便補上英文，只補還沒填的。
 */
return new class extends Migration
{
    private const NAMES = [
        'need_bin' => 'A bin is needed here',
        'broken' => 'Bin broken',
        'smelly' => 'Smelly',
        'full' => 'Bin full!',
        'missing' => 'Bin gone',
        'exists' => 'Still here',
        'bag_empty' => 'Out of dog bags',
        'dumping' => 'Household dumping',
        'hazard' => 'Poison bait / hazard',
    ];

    public function up(): void
    {
        Schema::table('report_categories', function (Blueprint $table) {
            $table->string('name_en', 64)->nullable()->after('name');
        });

        foreach (self::NAMES as $slug => $name) {
            DB::table('report_categories')->where('slug', $slug)->whereNull('name_en')->update(['name_en' => $name]);
        }
        // 只清分類快取這一個鍵，不用 Cache::flush（正式機多站共用快取）
        Cache::forget('petmin:report-categories:v2');
    }

    public function down(): void
    {
        Schema::table('report_categories', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
        Cache::forget('petmin:report-categories:v2');
    }
};
