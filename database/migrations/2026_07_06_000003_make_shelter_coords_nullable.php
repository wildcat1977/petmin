<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * data.taipei 收容所 dataset 只有門牌地址、無經緯度，需事後 geocode 補座標。
     * 座標改為 nullable：先建檔（保留 name/address/capacity），座標由每日同步逐批補齊。
     * 尚未取得座標者不上圖（map API whereNotNull 過濾）。
     */
    public function up(): void
    {
        Schema::table('shelters', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
            $table->timestamp('geocoded_at')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('shelters', function (Blueprint $table) {
            $table->dropColumn('geocoded_at');
            $table->decimal('latitude', 10, 7)->nullable(false)->change();
            $table->decimal('longitude', 10, 7)->nullable(false)->change();
        });
    }
};
