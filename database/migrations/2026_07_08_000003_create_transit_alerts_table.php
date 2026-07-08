<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('system');            // metro / gondola / bus
            $table->string('identifier')->nullable();
            $table->string('scope_name')->nullable(); // 線/路名
            $table->unsignedTinyInteger('status')->default(1); // 0 全停 / 1 正常 / 2 異常
            $table->unsignedSmallInteger('cause')->nullable();
            $table->unsignedSmallInteger('effect')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_alerts');
    }
};
