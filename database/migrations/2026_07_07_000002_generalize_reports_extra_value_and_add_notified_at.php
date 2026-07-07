<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spec §11.7/§11.8：water_depth_cm 一般化為 extra_value（各分類選填數值欄），
 * 加 notified_at（已通報，Filament 手動標記）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('water_depth_cm', 'extra_value');
            $table->timestamp('notified_at')->nullable()->after('verification_level');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports ALTER COLUMN extra_value TYPE INTEGER');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports ALTER COLUMN extra_value TYPE SMALLINT');
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('extra_value', 'water_depth_cm');
            $table->dropColumn('notified_at');
        });
    }
};
