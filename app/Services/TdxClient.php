<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** TDX OAuth2 client_credentials；token 快取為純字串（禁快取物件），過期/401 重取。 */
class TdxClient
{
    private const TOKEN_CACHE_KEY = 'hazdar:tdx:token';

    public function configured(): bool
    {
        $c = config('hazdar.sources.transit');

        return ! empty($c['tdx_client_id']) && ! empty($c['tdx_client_secret']);
    }

    public function token(bool $fresh = false): ?string
    {
        if (! $fresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $c = config('hazdar.sources.transit');
        try {
            $resp = Http::asForm()->timeout(15)->post($c['token_url'], [
                'grant_type' => 'client_credentials',
                'client_id' => $c['tdx_client_id'],
                'client_secret' => $c['tdx_client_secret'],
            ]);
            $token = (string) data_get($resp->json(), 'access_token', '');
            if ($resp->successful() && $token !== '') {
                Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addHours(23));

                return $token;
            }
        } catch (Throwable $e) {
            Log::warning('hazdar: TDX token failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @return array<mixed>|null */
    public function get(string $path): ?array
    {
        $c = config('hazdar.sources.transit');
        $token = $this->token();
        if ($token === null) {
            return null;
        }

        $url = rtrim($c['base_url'], '/').$path;
        $resp = Http::withToken($token)->timeout(15)->get($url, ['$format' => 'JSON']);
        if ($resp->status() === 401) {
            $token = $this->token(fresh: true); // 重取一次
            if ($token === null) {
                return null;
            }
            $resp = Http::withToken($token)->timeout(15)->get($url, ['$format' => 'JSON']);
        }

        return $resp->successful() ? (array) $resp->json() : null;
    }
}
