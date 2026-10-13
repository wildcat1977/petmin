<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 已登入使用者瀏覽次要頁面（footer-links 共用後，確認登入態不炸）。 */
class LoggedInPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_secondary_pages(): void
    {
        $user = User::factory()->create();
        foreach (['/', '/profile', '/reports', '/news', '/contact', '/candidates', '/leaderboard'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }
}
