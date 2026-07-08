<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * 受保護管理員自動授權（PROTECTED_ADMIN_EMAILS）：名單內的 email 以 Google/LINE 登入時
 * 自動補 is_admin，資料庫被清空/重建後重新登入即恢復後台權限，不必手動進 DB 設定。
 */
class ProtectedAdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $id = 'gid-1'): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getName')->andReturn('Boss Person');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.test/a.png');

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_protected_email_is_granted_admin_on_first_login(): void
    {
        config(['auth.protected_admin_emails' => ['boss@example.com']]);
        $this->fakeGoogleUser('boss@example.com');

        $this->get('/auth/google/callback')->assertRedirect();

        $user = User::where('email', 'boss@example.com')->sole();
        $this->assertTrue($user->is_admin, '受保護名單內的 email 首次登入應自動成為管理員');
    }

    public function test_non_protected_email_stays_non_admin(): void
    {
        config(['auth.protected_admin_emails' => ['boss@example.com']]);
        $this->fakeGoogleUser('rando@example.com', 'gid-2');

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertFalse(User::where('email', 'rando@example.com')->sole()->is_admin);
    }

    public function test_existing_protected_user_regains_admin_on_relogin(): void
    {
        // 正是使用者遇到的情境：帳號被清、重新註冊後 is_admin=false，重登應自動補回
        config(['auth.protected_admin_emails' => ['boss@example.com']]);
        $user = User::factory()->create([
            'email' => 'boss@example.com',
            'provider' => 'google',
            'provider_id' => 'gid-1',
            'is_admin' => false,
        ]);
        $this->fakeGoogleUser('boss@example.com');

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertTrue($user->refresh()->is_admin);
    }
}
