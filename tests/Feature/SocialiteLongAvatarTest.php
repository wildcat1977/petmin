<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Google 新版頭像 URL（lh3.googleusercontent.com/a-/ALV-…）可超過 255 字元，
 * users.avatar 若停在 varchar(255)，新用戶首次註冊會在 PostgreSQL 直接 500
 * （2026-09-01 friendly-store-map 實際事故，本專案為同款 schema）。
 */
class SocialiteLongAvatarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 測試跑在 SQLite，而 SQLite 不強制 varchar 長度，光靠寫入長字串無法重現線上事故；
     * 因此直接對欄位型別斷言，這才是唯一會在 varchar(255) 下真的失敗的檢查。
     */
    public function test_users_avatar_column_is_not_length_capped(): void
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'avatar');

        $this->assertSame(
            'text',
            $column['type_name'],
            'users.avatar 必須是 text；varchar(255) 會讓超長 Google 頭像 URL 在註冊時炸掉'
        );
    }

    public function test_registration_with_over_255_char_avatar_url_succeeds(): void
    {
        $longAvatar = 'https://lh3.googleusercontent.com/a-/'.str_repeat('AVeryLongToken', 30); // ~455 字元

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn('112513715725984521823');
        $socialiteUser->shouldReceive('getName')->andReturn('李Yuli');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getEmail')->andReturn('newcomer@example.com');
        $socialiteUser->shouldReceive('getAvatar')->andReturn($longAvatar);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect();

        $user = User::where('email', 'newcomer@example.com')->sole();
        $this->assertGreaterThan(255, strlen($user->avatar));
        $this->assertSame($longAvatar, $user->avatar, '頭像 URL 不應被截斷');
    }
}
