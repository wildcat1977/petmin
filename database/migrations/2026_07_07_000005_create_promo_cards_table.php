<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 圖卡專區（spec §11.6）：沈伯洋／議員候選人宣傳圖卡，供 /cards 頁面分區展示，
     * 內容純由 Filament 後台手動維護。
     */
    public function up(): void
    {
        Schema::create('promo_cards', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('image_path', 500);
            $table->string('group', 32);              // shen | councilor（PromoCard::GROUP_LABELS）
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_cards');
    }
};
