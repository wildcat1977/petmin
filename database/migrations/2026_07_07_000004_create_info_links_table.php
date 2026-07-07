<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 交通與清運資訊彙整看板（取代物資看板，spec §11.5）：官方連結彙整，
     * 分區顯示於 EOC 大屏，內容純由 Filament 後台手動維護。
     */
    public function up(): void
    {
        Schema::create('info_links', function (Blueprint $table) {
            $table->id();
            $table->string('section', 32);
            $table->string('label');
            $table->string('url', 500);
            $table->string('status_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('info_links')->insert([
            ['section' => 'transit', 'label' => '台北捷運', 'url' => 'https://www.metro.taipei/', 'status_text' => null, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'transit', 'label' => '台北公車動態', 'url' => 'https://ebus.gov.taipei/', 'status_text' => null, 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'transit', 'label' => '北市 EOC 交通資訊', 'url' => 'https://eoc.gov.taipei/Page/Transport', 'status_text' => null, 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'water_gate', 'label' => '水門開放狀態', 'url' => 'https://gis.gov.taipei/ttfp', 'status_text' => null, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'water_gate', 'label' => '抽水站資訊（data.taipei）', 'url' => 'https://data.taipei/dataset/detail?id=2bbfb30e-de58-43bd-9cc9-b56e9a6b5369', 'status_text' => null, 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'sanitation', 'label' => '清運與環保局公告', 'url' => 'https://www.dep.gov.taipei/News.aspx?n=68D9FDB562F2B943&sms=78D644F2755ACCAA', 'status_text' => null, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['section' => 'shelter', 'label' => '避難收容所開設公告', 'url' => 'https://www.doe.gov.taipei/News_Content.aspx?n=BA819C04388F2B6E&sms=69B4E6B26379EE4E&s=929AD2A327986813', 'status_text' => null, 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('info_links');
    }
};
