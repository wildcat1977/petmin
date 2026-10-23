<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 狗狗圖鑑：每筆回報送出後隨機播一段扭蛋劇情、抽一張卡（卡片種類在 config petmin.gacha.cards）。
 * 一筆回報最多一張卡（report_id unique），避免重新整理或重送事件重複抽。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dog_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('card_key', 32)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dog_cards');
    }
};
