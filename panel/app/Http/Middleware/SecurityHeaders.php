<?php

namespace App\Http\Middleware;

use App\Oidc\OidcSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Security Policy and HSTS for every answer of the panel (E-157).
 *
 * Scripts only come from the panel itself or carry the nonce of this
 * request (Vite and Livewire add it on their own). No eval: Livewire loads
 * the CSP-safe Alpine build. Inline styles stay allowed, because Tabler and
 * the charts set widths and positions in style attributes.
 *
 * HSTS only over HTTPS, without subdomains and without preload: the panel
 * domain may be a subdomain of a customer's domain.
 */
class SecurityHeaders
{
    public const HSTS = 'max-age=31536000';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::policy($nonce));
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // Linking Pocket ID sends a form that ends at the provider.
            'form-action '.implode(' ', array_filter(["'self'", self::issuerOrigin()])),
            "frame-ancestors 'self'",
        ]);
    }

    private static function issuerOrigin(): ?string
    {
        $issuer = rescue(fn () => OidcSettings::issuer(), null, report: false);
        $parts = $issuer !== null ? parse_url($issuer) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
