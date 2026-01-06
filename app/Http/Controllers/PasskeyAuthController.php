<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\DeviceTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Diglactic\Breadcrumbs\Breadcrumbs;

class PasskeyAuthController extends Controller
{
    protected $deviceTokenService;

    public function __construct(DeviceTokenService $deviceTokenService)
    {
        $this->deviceTokenService = $deviceTokenService;
    }

    /**
     * Display the passkey login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/PasskeyLogin', [
            'status' => session('status'),
        ]);
    }

    /**
     * Display the passkey registration view (for new users).
     */
    public function registerCreate(): Response
    {
        return Inertia::render('Auth/PasskeyRegister', [
            'hasExistingPasskeys' => false,
        ])->with('breadcrumbs', function () {
            return Breadcrumbs::generate('passkey.register');
        });
    }

    /**
     * Handle an incoming registration request (create new user).
     */
    public function registerStore(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:' . User::class,
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        Auth::login($user);

        // Redirect to passkey registration page
        return redirect()->route('passkey.register')->with('status', 'Account created! Now register your passkey.');
    }

    /**
     * Destroy an authenticated session (logout).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();
        
        // Revoke the current device token if it exists
        if ($user) {
            $token = $request->cookie($this->deviceTokenService->getCookieName());
            if ($token) {
                $device = $this->deviceTokenService->verifyDeviceToken($token, $request);
                if ($device) {
                    $device->delete();
                }
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Forget the remember device cookie
        Cookie::queue(Cookie::forget($this->deviceTokenService->getCookieName()));

        // Force a full page redirect to ensure fresh CSRF token
        return redirect('/login');
    }
}
