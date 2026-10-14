<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 官方桶位每日同步（bins:sync-open-data）：記錄桶最後一次出現在官方清單、以及從清單移除的時間
 * （移除＝「消失的桶」的官方證據）。
 *
 * 並刪除舊的「狗便袋箱」列：原 dog_bag_boxes.csv 其實是「行人專用清潔箱總表（景點商圈周邊130點）」，
 * 被誤當狗便袋箱匯入；正確的狗便清潔袋箱（469 點）與景點商圈清潔箱改由同步指令重新匯入。
 * 刪掉的是錯誤資料，down() 不還原。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trash_bins', function (Blueprint $table) {
            $table->timestamp('last_seen_in_source_at')->nullable();
            $table->timestamp('removed_from_source_at')->nullable();
        });

        DB::table('trash_bins')->where('source', 'official_dog_bag_box')->delete();
    }

    public function down(): void
    {
        Schema::table('trash_bins', function (Blueprint $table) {
            $table->dropColumn(['last_seen_in_source_at', 'removed_from_source_at']);
        });
    }
};
