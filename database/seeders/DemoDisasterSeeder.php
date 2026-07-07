<?php

namespace Database\Seeders;

use App\Models\Report;
use App\Models\Shelter;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 本地開發示範資料：颱風災時的台北市回報樣態。
 * 含刻意製造的佐證群（同網格 3 個不同帳號）供驗證視覺分層。
 * 不在 production 執行（DatabaseSeeder 控制）。
 */
class DemoDisasterSeeder extends Seeder
{
    /** 台北市各行政區代表點（近似中心） */
    private const DISTRICT_CENTERS = [
        [25.0330, 121.5654], // 中正
        [25.0630, 121.5130], // 大同
        [25.0880, 121.5250], // 士林
        [25.1180, 121.5240], // 北投（土石流警戒區）
        [25.0820, 121.5580], // 中山
        [25.0490, 121.5770], // 松山
        [25.0410, 121.5570], // 大安
        [25.0360, 121.5010], // 萬華
        [25.0260, 121.5620], // 信義
        [24.9880, 121.5410], // 文山
        [25.0690, 121.5870], // 內湖
        [25.0450, 121.6100], // 南港
    ];

    public function run(): void
    {
        $users = User::factory()->count(30)->create();

        // 一般散布回報：近 24 小時、各分類權重仿災時樣態（淹水最多）
        $categories = [
            Report::CATEGORY_FLOOD => 90,
            Report::CATEGORY_ROAD_BLOCKED => 45,
            Report::CATEGORY_POWER_OUTAGE => 30,
            Report::CATEGORY_STRUCTURAL_DAMAGE => 20,
            'water_outage' => 15,
            'falling_object' => 25,
        ];

        foreach ($categories as $category => $count) {
            for ($i = 0; $i < $count; $i++) {
                [$lat, $lng] = self::DISTRICT_CENTERS[array_rand(self::DISTRICT_CENTERS)];
                Report::factory()->create([
                    'user_id' => $users->random()->id,
                    'category' => $category,
                    'latitude' => $lat + (mt_rand(-800, 800) / 100000),
                    'longitude' => $lng + (mt_rand(-800, 800) / 100000),
                    'status' => mt_rand(1, 10) <= 3 ? Report::STATUS_APPROVED : Report::STATUS_PENDING,
                    'created_at' => now()->subMinutes(mt_rand(0, 1440)),
                ]);
            }
        }

        // 佐證群：同一點位 3 個不同帳號 → EvaluateCorroboration 自動升級
        foreach ([[25.0335, 121.5650, Report::CATEGORY_FLOOD], [25.1175, 121.5245, Report::CATEGORY_ROAD_BLOCKED]] as [$lat, $lng, $category]) {
            $users->random(3)->each(function (User $user) use ($lat, $lng, $category) {
                Report::factory()->create([
                    'user_id' => $user->id,
                    'category' => $category,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'created_at' => now()->subMinutes(mt_rand(0, 120)),
                ]);
            });
        }

        // 官方核實示範
        Report::query()->latest()->first()?->update([
            'verification_level' => Report::VERIFICATION_OFFICIAL,
        ]);

        // 收容所示範（正式清單由 data.taipei 同步）
        collect([
            ['臺北市立中正國民中學', 25.0323, 121.5225, 450, true],
            ['臺北市立士林高級商業職業學校', 25.0951, 121.5187, 800, true],
            ['臺北市立文山特殊教育學校', 24.9891, 121.5556, 300, false],
        ])->each(fn ($row) => Shelter::firstOrCreate(
            ['external_id' => 'demo-'.md5($row[0])],
            ['name' => $row[0], 'latitude' => $row[1], 'longitude' => $row[2],
                'capacity' => $row[3], 'is_open' => $row[4], 'source' => 'demo'],
        ));
    }
}
