<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回報分類後台化：分類移入 DB，reports.category 維持 slug 字串鍵。
 * 基礎 7 類直接在 migration 內 seed（部署即生效，不依賴 db:seed）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();   // 建立後唯讀，程式與 reports.category 以此為鍵
            $table->string('name', 32);
            $table->string('emoji', 8)->nullable();
            $table->char('color', 7);               // hex，如 #38bdf8
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('extra_field')->nullable(); // {label, unit, min, max, presets?: [{value, hint}]}
            $table->timestamps();
        });

        $now = now();
        $fullExtra = json_encode([
            'label' => '滿溢程度', 'unit' => '%', 'min' => 0, 'max' => 100,
            'presets' => [
                ['value' => 50, 'hint' => '半滿'], ['value' => 80, 'hint' => '快滿'],
                ['value' => 100, 'hint' => '滿出來'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        // slug 是程式鍵（DemandScoreService / TrashBinObserver 依賴 need_bin / full / exists / missing / dumping）
        DB::table('report_categories')->insert([
            ['slug' => 'need_bin', 'name' => '這裡需要垃圾桶', 'emoji' => '🗑️', 'color' => '#f59e0b', 'sort_order' => 10, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'full', 'name' => '桶滿了', 'emoji' => '🤢', 'color' => '#ef4444', 'sort_order' => 20, 'is_active' => true, 'extra_field' => $fullExtra, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'missing', 'name' => '桶消失了', 'emoji' => '👻', 'color' => '#a78bfa', 'sort_order' => 30, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'exists', 'name' => '桶還在', 'emoji' => '✅', 'color' => '#22c55e', 'sort_order' => 40, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'bag_empty', 'name' => '狗便袋箱沒袋了', 'emoji' => '🐕', 'color' => '#38bdf8', 'sort_order' => 50, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'dumping', 'name' => '家戶垃圾亂丟', 'emoji' => '🚫', 'color' => '#fb923c', 'sort_order' => 60, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'hazard', 'name' => '毒餌／危險物', 'emoji' => '☠️', 'color' => '#c084fc', 'sort_order' => 70, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_categories');
    }
};
