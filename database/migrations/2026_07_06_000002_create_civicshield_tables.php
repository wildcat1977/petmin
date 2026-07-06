<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CivicShield 外部資料落地表（spec §4.2）。
     * 即時性快取（雷達回波圖檔、颱風路徑、CWA 最新值）走 Redis，不進表。
     */
    public function up(): void
    {
        // CWA 觀測歷史，供 24H 趨勢圖；保留 7 天 daily prune
        Schema::create('weather_observations', function (Blueprint $table) {
            $table->id();
            $table->string('station_id', 16);
            $table->timestamp('observed_at');
            $table->decimal('temp', 4, 1)->nullable();
            $table->decimal('rain_1h', 6, 1)->nullable();
            $table->decimal('wind_speed', 5, 1)->nullable();
            $table->decimal('visibility', 6, 1)->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'observed_at']);
        });

        // NCDR 民生示警（CAP）快取，依 cap_id upsert
        Schema::create('external_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('cap_id')->unique();
            $table->string('alert_type', 64);
            $table->string('headline', 512);
            $table->string('severity', 32)->nullable();
            $table->jsonb('areas')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        // 收容所（data.taipei 同步 + Filament 手動切開設狀態）
        Schema::create('shelters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address')->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('is_open')->default(false);
            $table->string('source', 32)->default('data.taipei');
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();
        });

        // 物資看板（純官方手動維護）
        Schema::create('dashboard_resources', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->integer('current_qty')->default(0);
            $table->integer('max_qty');
            $table->string('unit', 16)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_resources');
        Schema::dropIfExists('shelters');
        Schema::dropIfExists('external_alerts');
        Schema::dropIfExists('weather_observations');
    }
};
