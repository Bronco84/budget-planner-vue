<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Core hardening headers — applied in EVERY environment (including staging),
        // not just production, so non-prod deployments are protected too.

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // XSS protection (legacy browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions policy (restrict access to browser features)
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Content-Security-Policy — shipped in Report-Only mode first so violations
        // can be observed (via the browser console / a report endpoint) WITHOUT
        // breaking Plaid Link (cdn.plaid.com iframe + scripts) or Vite. Once the
        // report stream is clean, promote this to the enforcing
        // `Content-Security-Policy` header. Skipped in local dev to avoid noise
        // from Vite's HMR (inline scripts / eval / ws).
        if (! app()->environment('local')) {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' https://cdn.plaid.com",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self' https://*.plaid.com",
                "frame-src 'self' https://cdn.plaid.com https://*.plaid.com",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
            $response->headers->set('Content-Security-Policy-Report-Only', $csp);
        }

        // HSTS stays production-only — sending it over plain HTTP in dev/staging
        // would wrongly pin browsers to HTTPS for the .test domain.
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
