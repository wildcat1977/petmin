<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// 注意：不可使用 WithoutModelEvents——geohash7 推導、佐證判定、桶狀態同步與加點都掛在 model events 上
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // 示範資料僅在本地環境產生
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
