<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\DeviceTokenService;
use App\Services\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

use function Illuminate\Support\defer;

class MagicLinkController extends Controller
{
    public function __construct(
        private MagicLinkService $magicLinkService,
        private DeviceTokenService $deviceTokenService,
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Display the magic link request form.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/MagicLinkRequest', [
            'status' => session('status'),
        ]);
    }

    /**
     * Send a magic link to the user's email. The response is identical whether
     * or not the address has an account, so the form cannot be used to
     * enumerate registered emails. The lookup and the send run after the
     * response is sent, so a registered address does not answer slower (by the
     * token insert and mail-provider round trip) than an unknown one.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = $validated['email'];

        defer(static function () use ($email) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                return;
            }

            try {
                app(MagicLinkService::class)->sendMagicLink($user);
            } catch (Throwable $e) {
                // A mail outage must not change the (already sent) response.
                Log::error('Magic link email could not be sent', [
                    'user_id' => $user->id,
                    'exception' => $e,
                ]);
            }
        });

        return back()->with('status', 'If an account exists for that email, a sign-in link is on its way.');
    }

    /**
     * Authenticate the user via magic link.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        // Verify the token (only consumed when it belongs to this email)
        $user = $this->magicLinkService->verifyToken($request->token, $request->email);

        if ($user) {
            Auth::login($user);
            $request->session()->regenerate();

            // Mark session as magic link authenticated (ephemeral, no device trust)
            $request->session()->put('auth_method', 'magic_link');

            // Check if user has any passkeys registered
            $hasPasskeys = $user->passkeys()->exists();

            // Redirect with appropriate message
            if (! $hasPasskeys) {
                return redirect()->route('passkey.register')
                    ->with('status', 'Welcome! Please register a passkey for secure access to your account.');
            }

            return redirect()->intended(route('budgets.index'))
                ->with('status', 'Logged in successfully! Consider adding a passkey to this device for easier access.')
                ->with('show_passkey_prompt', true);
        }

        return redirect()->route('login')->withErrors(['magic_link' => 'Invalid or expired magic link.']);
    }
}
