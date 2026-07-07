<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['zh_TW', 'en'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale')
            ?? $this->preferredLocale($request);

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    private function preferredLocale(Request $request): string
    {
        $preferred = $request->getPreferredLanguage(['zh_TW', 'zh', 'en']);

        return match (true) {
            str_starts_with($preferred, 'zh') => 'zh_TW',
            default => 'en',
        };
    }
}
