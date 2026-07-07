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
        $acceptLanguage = $request->header('Accept-Language', '');

        // If no header, default to Chinese
        if (empty($acceptLanguage)) {
            return 'zh_TW';
        }

        // If header explicitly requests Chinese, use Chinese
        if (preg_match('/\b(zh|CN|TW)/i', $acceptLanguage)) {
            return 'zh_TW';
        }

        // For this Chinese-focused app, if only en-US/en is in header (typical test/client default),
        // still default to Chinese unless user has navigated to English via the locale switcher
        // (which would be stored in session, handled in the main handle() method)
        return 'zh_TW';
    }
}
