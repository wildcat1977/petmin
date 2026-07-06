<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CivicShield schema delta（spec §4.1）：
     *   - type（rat/poison）改為 category（flood/road_blocked/power_outage/structural_damage）
     *   - status 語義收斂為「內容適切性審核」（pending/approved/rejected），
     *     災情核實改由正交欄位 verification_level 承載
     *   - 新增 water_depth_cm / geohash7 / verification_level / resolved_at
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('type', 'category');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports ALTER COLUMN category TYPE VARCHAR(32)');
            DB::statement('ALTER TABLE reports ALTER COLUMN category DROP DEFAULT');
            DB::statement("UPDATE reports SET status = 'approved' WHERE status IN ('reported_1999','resolved')");
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending','approved','rejected'))");
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->smallInteger('water_depth_cm')->nullable()->after('category');
            $table->char('geohash7', 7)->nullable()->after('longitude');
            $table->enum('verification_level', ['unverified', 'corroborated', 'official'])
                ->default('unverified')->after('status');
            $table->timestamp('resolved_at')->nullable()->after('verification_level');

            $table->index('geohash7');
            $table->index(['category', 'created_at']);
            $table->index(['geohash7', 'category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['geohash7']);
            $table->dropIndex(['category', 'created_at']);
            $table->dropIndex(['geohash7', 'category', 'created_at']);
            $table->dropColumn(['water_depth_cm', 'geohash7', 'verification_level', 'resolved_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('pending','approved','rejected','reported_1999','resolved'))");
            DB::statement('ALTER TABLE reports ALTER COLUMN category TYPE VARCHAR(16)');
            DB::statement("ALTER TABLE reports ALTER COLUMN category SET DEFAULT 'rat'");
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('category', 'type');
        });
    }
};
