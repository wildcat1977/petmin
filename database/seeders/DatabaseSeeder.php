<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// 注意：不可使用 WithoutModelEvents——geohash7 推導與佐證判定都掛在 Report model events 上
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DashboardResourceSeeder::class);

        // 示範災情資料僅在本地環境產生
        if (app()->environment('local')) {
            $this->call(DemoDisasterSeeder::class);
        }
    }
}
