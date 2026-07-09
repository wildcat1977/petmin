<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 後台公告整合為單一富文本欄位（spec §12.17）：
 * 表單只留 body（富文本），content 退為 legacy。既有僅有 content 的公告
 * 回填進 body（純文字轉義 + nl2br），避免既有公告在改讀 body 後消失。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 既有純文字 content 回填至空白的 body（保留換行、轉義避免注入）
        DB::table('announcements')
            ->where(function ($q) {
                $q->whereNull('body')->orWhere('body', '');
            })
            ->whereNotNull('content')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('announcements')
                    ->where('id', $row->id)
                    ->update(['body' => '<p>'.nl2br(e($row->content)).'</p>']);
            });

        // content 不再是必填來源，退為 nullable legacy 欄位
        Schema::table('announcements', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });
    }
};
