<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 缺桶報表（行政區排行＋候選地點明細）：行政區、候選名次（300m 去重）、最近清潔箱距離、各來源分數拆欄，
 * 讓篩選、排序、分頁直接查欄位。demand_cells 由 demand:recompute 整批重算，部署後重算一次即補齊。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demand_cells', function (Blueprint $table) {
            $table->string('district', 8)->nullable()->index();
            $table->unsignedInteger('candidate_rank')->nullable()->index();
            $table->unsignedInteger('nearest_bin_m')->nullable();
            $table->decimal('dog_score', 8, 2)->default(0);
            $table->decimal('mrt_score', 8, 2)->default(0);
            $table->decimal('bus_score', 8, 2)->default(0);
            $table->decimal('report_score', 8, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('demand_cells', function (Blueprint $table) {
            $table->dropIndex(['district']);
            $table->dropIndex(['candidate_rank']);
            $table->dropColumn(['district', 'candidate_rank', 'nearest_bin_m', 'dog_score', 'mrt_score', 'bus_score', 'report_score']);
        });
    }
};
