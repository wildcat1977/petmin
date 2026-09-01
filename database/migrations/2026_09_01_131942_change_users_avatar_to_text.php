<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google OAuth 的頭像 URL（lh3.googleusercontent.com/a-/ALV-…）可超過 255 字元，
     * varchar(255) 會讓新用戶註冊直接 500（2026-09-01 friendly-store-map 實際事故）。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->change();
        });
    }
};
