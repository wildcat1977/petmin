<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 佔領命名：每次送審一列，留下歷史。桶目前的名字＝最新一筆 approved（被超越時舊名保留，直到新第一名命名）；
 * 後台撤下就改成 rejected，地圖自動退回上一個通過的名字。命名資格從 reports 即時算，不存在這裡。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bin_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trash_bin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('status', 16)->default('pending');           // pending | approved | rejected
            $table->string('rejection_reason', 16)->nullable();          // ai | admin（格式與黑名單當場擋下，不入庫）
            $table->string('moderation_note', 255)->nullable();          // AI 給的理由，後台複核用
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['trash_bin_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bin_names');
    }
};
