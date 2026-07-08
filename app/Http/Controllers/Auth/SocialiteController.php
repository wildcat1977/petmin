<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /**
     * 將使用者導向 OAuth 提供者
     */
    public function redirect(string $provider): RedirectResponse
    {
        $driver = Socialite::driver($provider);

        // LINE 且已開啟 email scope 時，要求 openid + email 權限
        if ($provider === 'line' && config('services.line.email_scope')) {
            $driver = $driver->scopes(['profile', 'openid', 'email']);
        }

        return $driver->redirect();
    }

    /**
     * 後台專用：標記 session 後導向 Google OAuth
     */
    public function adminRedirect(): RedirectResponse
    {
        session(['admin_oauth' => true]);

        return Socialite::driver('google')->redirect();
    }

    /**
     * 處理 OAuth 回呼，建立或登入使用者，再導回首頁並請前端開啟回報表單
     */
    public function callback(string $provider): RedirectResponse
    {
        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            if (session()->pull('admin_oauth')) {
                return redirect('/admin/login')->withErrors(['email' => '登入失敗，請再試一次']);
            }

            return redirect()->route('home')->with('auth_error', '登入失敗，請再試一次');
        }

        $user = User::where('provider', $provider)
            ->where('provider_id', $oauthUser->getId())
            ->first();

        if (! $user && $oauthUser->getEmail()) {
            $user = User::where('email', $oauthUser->getEmail())->first();
        }

        if ($user) {
            $user->fill([
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
                'avatar' => $oauthUser->getAvatar(),
            ])->save();
        } else {
            $user = User::create([
                'name' => $oauthUser->getName() ?: ($oauthUser->getNickname() ?: '匿名市民'),
                'email' => $oauthUser->getEmail() ?: $provider.'_'.$oauthUser->getId().'@ratdar.local',
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
                'avatar' => $oauthUser->getAvatar(),
            ]);
        }

        $this->grantAdminIfProtected($user);

        // 後台 OAuth 流程
        if (session()->pull('admin_oauth')) {
            if (! $user->is_admin) {
                return redirect('/admin/login')->withErrors(['email' => '此帳號沒有後台管理權限。']);
            }

            Auth::login($user, remember: true);

            return redirect('/admin');
        }

        Auth::login($user, remember: true);

        return redirect()->route('home')->with('open_report_form', true);
    }

    /**
     * PROTECTED_ADMIN_EMAILS 名單內的 email 登入時自動補上 is_admin。
     *
     * 這份名單語意上就是「永久管理員」（EditUser 也禁止在後台撤銷其權限）；
     * 資料庫若被清空/重建，這些人重新登入即自動恢復後台權限，不必再手動進 DB 設定。
     */
    private function grantAdminIfProtected(User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        if ($user->email && in_array($user->email, User::protectedAdminEmails(), true)) {
            $user->forceFill(['is_admin' => true])->save();
        }
    }

    /**
     * LIFF 內建瀏覽器專用：用 access token 向 LINE API 取得使用者資料，再車回一般登入流程
     */
    public function liffCallback(): RedirectResponse
    {
        $token = request()->string('access_token')->toString();
        $idToken = request()->string('id_token')->toString();
        $redirect = request()->string('redirect', '/')->toString();

        if (empty($token)) {
            return redirect($redirect)->with('auth_error', 'LIFF 登入失敗，請再試一次');
        }

        // 向 LINE Profile API 取得使用者基本資料
        try {
            $resp = (new Client)->get('https://api.line.me/v2/profile', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 8,
            ]);
            $profile = json_decode((string) $resp->getBody(), true);
        } catch (\Throwable) {
            return redirect($redirect)->with('auth_error', 'LIFF 登入失敗，請再試一次');
        }

        $providerId = $profile['userId'] ?? null;
        if (! $providerId) {
            return redirect($redirect)->with('auth_error', 'LIFF 登入失敗，請再試一次');
        }

        // 若有 id_token 且開啟 email scope，向 LINE 驗證取得真實 email
        $email = null;
        if ($idToken && config('services.line.email_scope')) {
            try {
                $verifyResp = (new Client)->post('https://api.line.me/oauth2/v2.1/verify', [
                    'form_params' => [
                        'id_token' => $idToken,
                        'client_id' => config('services.line.client_id'),
                    ],
                    'timeout' => 8,
                ]);
                $claims = json_decode((string) $verifyResp->getBody(), true);
                $email = $claims['email'] ?? null;
            } catch (\Throwable) {
                // email 取得失敗不阻斷登入
            }
        }

        $user = User::where('provider', 'line')->where('provider_id', $providerId)->first();

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            $updates = ['avatar' => $profile['pictureUrl'] ?? $user->avatar];
            if ($email && str_ends_with($user->email, '@ratdar.local')) {
                $updates['email'] = $email;
            }
            $user->fill(array_merge($updates, ['provider' => 'line', 'provider_id' => $providerId]))->save();
        } else {
            $user = User::create([
                'name' => $profile['displayName'] ?? '匿名市民',
                'email' => $email ?: ('line_'.$providerId.'@ratdar.local'),
                'provider' => 'line',
                'provider_id' => $providerId,
                'avatar' => $profile['pictureUrl'] ?? null,
            ]);
        }

        $this->grantAdminIfProtected($user);

        Auth::login($user, remember: true);

        // 加上 liff_authed flag 防止前端御牧迴賦
        $separator = str_contains($redirect, '?') ? '&' : '?';

        return redirect($redirect.$separator.'liff_authed=1');
    }
}
