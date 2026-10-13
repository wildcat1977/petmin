<?php

namespace Database\Seeders;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * 本地示範資料：真實 open data（匯入指令）+ 假回報，含刻意製造的佐證群與點數。
 * 不在 production 執行（DatabaseSeeder 控制）。
 * 注意：不可使用 WithoutModelEvents——geohash7、佐證、桶狀態同步、加點都掛在 model events 上。
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('bins:import-clean-boxes');
        Artisan::call('bins:import-dog-bag-boxes');
        Artisan::call('proxies:import-bus-stops');
        Artisan::call('proxies:import-dog-parks');
        if (is_readable(database_path('data/mrt_stations.csv'))) {
            Artisan::call('proxies:import-mrt');
        }

        $users = User::factory()->count(12)->create();
        $bins = TrashBin::query()->kind(TrashBin::KIND_CLEAN_BOX)->inRandomOrder()->limit(60)->get();
        $bagBoxes = TrashBin::query()->kind(TrashBin::KIND_DOG_BAG_BOX)->inRandomOrder()->limit(15)->get();

        // 掛在桶上的回報：滿了 / 還在 / 消失了 / 家戶垃圾
        foreach ($bins as $i => $bin) {
            $category = match ($i % 5) {
                0, 1 => Report::CATEGORY_FULL,
                2 => Report::CATEGORY_EXISTS,
                3 => Report::CATEGORY_MISSING,
                default => Report::CATEGORY_DUMPING,
            };
            Report::factory()->create([
                'user_id' => $users->random()->id,
                'category' => $category,
                'trash_bin_id' => $bin->id,
                'extra_value' => $category === Report::CATEGORY_FULL ? [50, 80, 100][array_rand([50, 80, 100])] : null,
                'latitude' => $bin->latitude,
                'longitude' => $bin->longitude,
                'address' => '台北市 '.$bin->district,
                'status' => $i % 3 === 0 ? Report::STATUS_PENDING : Report::STATUS_APPROVED,
                'created_at' => now()->subHours(mt_rand(0, 72)),
            ]);
        }

        // 狗便袋箱沒袋了
        foreach ($bagBoxes as $box) {
            Report::factory()->create([
                'user_id' => $users->random()->id,
                'category' => Report::CATEGORY_BAG_EMPTY,
                'trash_bin_id' => $box->id,
                'latitude' => $box->latitude,
                'longitude' => $box->longitude,
                'address' => '台北市 '.$box->district,
                'status' => Report::STATUS_APPROVED,
                'created_at' => now()->subHours(mt_rand(0, 72)),
            ]);
        }

        // 「這裡需要桶」：散布在遛狗熱點附近（沒桶的地方）
        foreach ([[25.0470, 121.5265], [25.0790, 121.5520], [25.0570, 121.5820], [24.9870, 121.5750], [25.1030, 121.5450], [25.0330, 121.5355]] as [$lat, $lng]) {
            for ($i = 0; $i < 3; $i++) {
                Report::factory()->create([
                    'user_id' => $users->random()->id,
                    'category' => Report::CATEGORY_NEED_BIN,
                    'latitude' => $lat + (mt_rand(-150, 150) / 100000),
                    'longitude' => $lng + (mt_rand(-150, 150) / 100000),
                    'description' => '遛狗必經，狗便袋只能帶回家',
                    'status' => Report::STATUS_APPROVED,
                    'created_at' => now()->subHours(mt_rand(0, 72)),
                ]);
            }
        }

        // 佐證群：同一個桶 2 個不同帳號回報「滿了」→ EvaluateCorroboration 自動升級
        $target = $bins->first();
        $users->random(2)->each(fn (User $u) => Report::factory()->create([
            'user_id' => $u->id,
            'category' => Report::CATEGORY_FULL,
            'trash_bin_id' => $target->id,
            'latitude' => $target->latitude,
            'longitude' => $target->longitude,
            'status' => Report::STATUS_APPROVED,
        ]));

        Artisan::call('demand:recompute');
    }
}
