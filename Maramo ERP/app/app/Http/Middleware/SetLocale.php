<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language picked with ?lang=en|hi (remembered in the session),
 * falling back to APP_LOCALE.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'hi'];

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session() : null;

        if (in_array($request->query('lang'), self::SUPPORTED, true)) {
            $session?->put('locale', $request->query('lang'));
        }

        $locale = $session?->get('locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
