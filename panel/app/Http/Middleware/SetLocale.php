<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language for each request.
 *
 * Order: the language stored on the user account, then the language chosen
 * in this session, then the browser's preferred language, then APP_LOCALE.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('owpanel.locales'));

        $candidates = [
            $request->user()?->locale,
            $request->session()->get('locale'),
            $this->fromBrowser($request, $supported),
            config('app.locale'),
            config('app.fallback_locale'),
        ];

        foreach ($candidates as $locale) {
            if (in_array($locale, $supported, true)) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    /**
     * First supported language from the Accept-Language header, ordered by
     * the browser's preference. "de-AT" matches "de". Symfony's
     * getPreferredLanguage() is not used because it returns the first
     * supported language when nothing matches, which would hide APP_LOCALE.
     *
     * @param  list<string>  $supported
     */
    private function fromBrowser(Request $request, array $supported): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return null;
    }
}
