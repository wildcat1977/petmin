<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pump_stations', function (Blueprint $table) {
            $table->id();
            $table->string('stn_id')->unique();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('pump_num')->default(0);
            $table->unsignedSmallInteger('gate_num')->default(0);
            $table->string('pumb_status')->nullable();   // 停止 / 運轉中
            $table->string('door_status')->nullable();    // 閘門開啟 / 閘門關閉 / null
            $table->decimal('inner_value', 6, 2)->nullable();
            $table->decimal('outer_value', 6, 2)->nullable();
            $table->decimal('max_level', 6, 2)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_stations');
    }
};
