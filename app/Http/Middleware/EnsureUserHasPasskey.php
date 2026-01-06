<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPasskey
{
    /**
     * Handle an incoming request.
     *
     * Ensures that authenticated users have at least one passkey registered.
     * If not, redirect them to the passkey registration page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // If user is authenticated but has no passkeys, redirect to registration
        if ($user && !$user->webAuthnCredentials()->exists()) {
            // Allow access to the passkey registration route itself
            if (!$request->is('passkey/register') && !$request->is('webauthn/*') && !$request->is('logout')) {
                return redirect()->route('passkey.register')
                    ->with('error', 'You must register a passkey to continue.');
            }
        }

        return $next($request);
    }
}
