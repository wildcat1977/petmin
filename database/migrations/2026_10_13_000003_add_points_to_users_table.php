<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 遊戲化：核准回報得點、連續回報天數。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('points')->default(0)->after('is_private');
            $table->unsignedSmallInteger('streak_days')->default(0)->after('points');
            $table->date('last_report_date')->nullable()->after('streak_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['points', 'streak_days', 'last_report_date']);
        });
    }
};
