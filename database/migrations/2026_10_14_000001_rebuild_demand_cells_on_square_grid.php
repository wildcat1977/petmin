<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 需求格從 geohash6（1.1km×0.6km 長方形、只數格內訊號）改成固定原點的方格（DemandGrid，預設 150m）。
 * demand_cells 是 demand:recompute 整批重算的衍生資料，直接重建；部署後要跑一次 demand:recompute。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('demand_cells');
        Schema::create('demand_cells', function (Blueprint $table) {
            $table->id();
            $table->string('cell', 24)->unique();              // DemandGrid id「ix:iy」
            $table->integer('ix');
            $table->integer('iy');
            $table->decimal('latitude', 10, 7);                // 格中心
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
        Schema::create('demand_cells', function (Blueprint $table) {
            $table->id();
            $table->char('geohash6', 6)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('score', 8, 2)->index();
            $table->decimal('dumping_score', 8, 2)->default(0);
            $table->json('breakdown');
            $table->timestamp('computed_at');
        });
    }
};
