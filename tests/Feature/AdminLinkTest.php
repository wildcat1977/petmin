<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_sees_backend_link_on_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/profile')
            ->assertOk()
            ->assertSee('進入後台管理')
            ->assertSee(route('filament.admin.pages.dashboard'), false);
    }

    public function test_non_admin_user_does_not_see_backend_link(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('進入後台管理');
    }

    public function test_admin_panel_dashboard_shows_back_to_home_link(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('回首頁');
    }
}
