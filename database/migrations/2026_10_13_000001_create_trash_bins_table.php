<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 官方靜態點位：行人專用清潔箱 / 狗便清潔袋箱（open data 匯入）+ 市民回報新增的桶。
 * 回報掛在桶上（reports.trash_bin_id），桶的 status 由核准的回報更新。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trash_bins', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->nullable()->unique();
            $table->string('source', 32);              // official_clean_box | official_dog_bag_box | crowd
            $table->string('kind', 16)->index();       // clean_box | dog_bag_box
            $table->string('district', 16)->nullable()->index();
            $table->string('address')->nullable();
            $table->string('note')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->char('geohash7', 7)->index();
            $table->string('status', 16)->default('active')->index(); // active | missing | unverified
            $table->smallInteger('box_count')->default(1);           // 狗便袋箱總表一筆可能是「N處」
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedInteger('reports_count')->default(0);
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('trash_bin_id')->nullable()->after('category')
                ->constrained('trash_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trash_bin_id');
        });
        Schema::dropIfExists('trash_bins');
    }
};
