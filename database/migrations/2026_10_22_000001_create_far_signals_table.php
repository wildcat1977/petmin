<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「好遠！」匿名訊號：市民在 /find 看到最近的桶後，覺得太遠主動按下。
 * 隱私：座標只存需求網格（DemandGrid，預設 150m）的格中心、IP 只存雜湊。
 * 防灌票：同一 IP、同一格、同一天只算一次（unique 索引）。進需求分數時權重低於「這裡需要桶」回報且有上限。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('far_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // 登入就記，匿名為 null
            $table->string('cell', 32)->index();                       // DemandGrid 格子編號 ix:iy
            $table->decimal('latitude', 10, 7);                         // 格中心，不是使用者精確位置
            $table->decimal('longitude', 10, 7);
            $table->string('district', 16)->nullable()->index();
            $table->foreignId('nearest_bin_id')->nullable()->constrained('trash_bins')->nullOnDelete();
            $table->unsignedInteger('nearest_distance_m')->nullable(); // 當時推薦的最近距離
            $table->string('distance_mode', 9);                         // walking | estimated
            $table->char('ip_hash', 64);
            $table->date('signal_date');
            $table->timestamps();

            $table->unique(['ip_hash', 'cell', 'signal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('far_signals');
    }
};
