<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回報分類後台化（spec §11.7）：分類移入 DB，reports.category 維持 slug 字串鍵。
 * 基礎 6 類直接在 migration 內 seed（部署即生效，不依賴 db:seed）。
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
        $floodExtra = json_encode([
            'label' => '積水深度', 'unit' => 'cm', 'min' => 0, 'max' => 500,
            'presets' => [
                ['value' => 10, 'hint' => '腳踝'], ['value' => 30, 'hint' => '小腿'],
                ['value' => 50, 'hint' => '及膝'], ['value' => 100, 'hint' => '及腰'],
                ['value' => 150, 'hint' => '一層樓'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        DB::table('report_categories')->insert([
            ['slug' => 'flood', 'name' => '淹水', 'emoji' => '💧', 'color' => '#38bdf8', 'sort_order' => 10, 'is_active' => true, 'extra_field' => $floodExtra, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'road_blocked', 'name' => '道路中斷', 'emoji' => '🚧', 'color' => '#fb923c', 'sort_order' => 20, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'power_outage', 'name' => '停電', 'emoji' => '⚡', 'color' => '#facc15', 'sort_order' => 30, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'structural_damage', 'name' => '結構受損', 'emoji' => '🏚️', 'color' => '#f87171', 'sort_order' => 40, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'water_outage', 'name' => '停水', 'emoji' => '🚱', 'color' => '#a78bfa', 'sort_order' => 50, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'falling_object', 'name' => '大型物品掉落', 'emoji' => '🌳', 'color' => '#d97706', 'sort_order' => 60, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_categories');
    }
};
