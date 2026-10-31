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
        if ($user) {
            // Refresh the user to ensure we have the latest data
            $user->refresh();

            // Check if user has any WebAuthn credentials
            $hasPasskeys = $user->webAuthnCredentials()->exists();

            if (! $hasPasskeys) {
                // Allow access to passkey registration, sign-out and email verification
                if (! $request->is('passkey/register', 'webauthn/*', 'logout', 'verify-email', 'verify-email/*', 'email/verification-notification')) {
                    \Log::info('User has no passkeys, redirecting to registration', [
                        'user_id' => $user->id,
                        'path' => $request->path(),
                    ]);

                    $message = 'You must register a passkey to continue.';

                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => $message,
                            'redirect' => route('passkey.register'),
                        ], 409);
                    }

                    return redirect()->route('passkey.register')->with('error', $message);
                }
            }
        }

        return $next($request);
    }
}
