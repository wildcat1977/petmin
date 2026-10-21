<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「好遠！」匿名訊號：市民在 /find 看到最近的桶後，覺得太遠主動按下。
 * 記下當下位置與當時推薦的最近距離，作為「這附近缺桶」的需求線索（需人工複核才進候選）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('far_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // 登入就記，匿名為 null
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->char('geohash7', 7)->index();
            $table->string('district', 16)->nullable()->index();
            $table->foreignId('nearest_bin_id')->nullable()->constrained('trash_bins')->nullOnDelete();
            $table->unsignedInteger('nearest_distance_m')->nullable(); // 當時推薦的最近距離
            $table->string('distance_mode', 8);                         // walking | straight
            $table->char('ip_hash', 64)->nullable()->index();           // 冷卻與防灌用，不存原始 IP
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('far_signals');
    }
};
