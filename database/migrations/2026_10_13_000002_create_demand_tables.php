<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 需求分數：
 *   demand_proxies = 人流／遛狗 proxy 點位（捷運站、公車站牌、狗活動區、陳情熱點），匯入指令落地
 *   demand_cells   = geohash6 網格的需求分數快取（demand:recompute 重算），/candidates 與熱力圖讀這張
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_proxies', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->index();          // mrt | bus_stop | dog_park | park | petition
            $table->string('external_id', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('district', 16)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->char('geohash7', 7)->index();
            $table->decimal('weight', 8, 3)->default(1);  // mrt：出站量正規化 0~1；其餘預設 1
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'external_id']);
        });

        Schema::create('demand_cells', function (Blueprint $table) {
            $table->id();
            $table->char('geohash6', 6)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('score', 8, 2)->index();
            $table->decimal('dumping_score', 8, 2)->default(0); // 撤除風險：家戶垃圾亂丟回報集中
            $table->json('breakdown');                         // 各來源貢獻明細（證據）
            $table->timestamp('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_cells');
        Schema::dropIfExists('demand_proxies');
    }
};
