<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MagicLinkService;
use App\Services\DeviceTokenService;
use App\Services\DeviceFingerprintService;
use App\Mail\NewDeviceLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
     * Send a magic link to the user's email.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && $this->magicLinkService->sendMagicLink($user)) {
            return back()->with('status', 'Magic link sent to your email!');
        }

        return back()->withErrors(['email' => 'Failed to send magic link. Please try again.']);
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

        // Verify the token
        $user = $this->magicLinkService->verifyToken($request->token);

        // Also verify the email matches
        if ($user && $user->email === $request->email) {
            Auth::login($user);
            $request->session()->regenerate();

            // Create and set remember device cookie
            $trustedDevice = $this->deviceTokenService->createTrustedDevice(Auth::user(), $request);
            $cookie = $this->deviceTokenService->createCookie($trustedDevice);
            \Illuminate\Support\Facades\Cookie::queue($cookie);

            return redirect()->intended(route('budgets.index'))->with('status', 'Logged in successfully via magic link!');
        }

        return redirect()->route('login')->withErrors(['magic_link' => 'Invalid or expired magic link.']);
    }
}
