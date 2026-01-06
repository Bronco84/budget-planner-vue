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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply security headers in production
        if (app()->environment('production')) {
            // Enforce HTTPS
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
            
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
        }

        return $response;
    }
}

