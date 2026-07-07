<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首頁 chrome（spec §12.1/12.2/12.4/12.6）：配色 #002923、banner、
 * 頂帶 nav、底帶跑馬燈與 footer 分類新聞篇數。
 */
class HomeChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_uses_new_background_color(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('#002923', false)
            ->assertDontSee('bg-[#0d1117]', false);
    }

    public function test_home_shows_banner_image(): void
    {
        $this->get('/')->assertOk()->assertSee('Hazdar-Banner.png', false);
    }

    public function test_top_nav_has_profile_and_contact_for_auth_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'zh-TW')
            ->get('/')
            ->assertOk()
            ->assertSee('我的回報')
            ->assertSee('聯絡管理員');
    }
}
