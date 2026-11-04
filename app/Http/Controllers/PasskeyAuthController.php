<?php

namespace App\Http\Controllers;

use App\Models\BudgetInvitation;
use App\Models\User;
use App\Services\BudgetSharingService;
use App\Services\DeviceTokenService;
use Diglactic\Breadcrumbs\Breadcrumbs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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
     * Display the initial registration form (for new users to enter name/email).
     */
    public function showRegistrationForm(Request $request): Response
    {
        $invitation = null;
        $invitationError = null;

        if ($request->filled('invitation')) {
            $pending = BudgetInvitation::findPendingByToken((string) $request->query('invitation'));

            if ($pending) {
                $pending->load(['budget', 'inviter']);
                $invitation = [
                    'token' => (string) $request->query('invitation'),
                    'email' => $pending->email,
                    'role' => $pending->role->value,
                    'budget_name' => $pending->budget->name,
                    'invited_by' => $pending->inviter?->name,
                ];
            } else {
                $invitationError = 'This invitation link is invalid, expired or has already been used.';
            }
        }

        return Inertia::render('Auth/Register', [
            'invitation' => $invitation,
            'invitationError' => $invitationError,
        ]);
    }

    /**
     * Display the passkey registration view.
     * Can be used by both new users (after account creation) and authenticated users adding additional passkeys.
     */
    public function registerCreate(Request $request): Response
    {
        $user = Auth::user();
        $hasExistingPasskeys = $user ? $user->passkeys()->exists() : false;
        // Check if this is a new user from query param or if they have no passkeys
        $isNewUser = $request->query('new_user', false) || ($user && ! $hasExistingPasskeys);

        // Adding another passkey from the profile returns there; first-passkey
        // enrolment always continues to the budgets page. Only known values are
        // honoured so this cannot become an open redirect.
        $redirectTo = $hasExistingPasskeys && $request->query('return') === 'profile'
            ? route('profile.edit')
            : route('budgets.index');

        return Inertia::render('Auth/PasskeyRegister', [
            'hasExistingPasskeys' => $hasExistingPasskeys,
            'isNewUser' => $isNewUser,
            'redirectTo' => $redirectTo,
        ])->with('breadcrumbs', function () {
            return Breadcrumbs::generate('passkey.register');
        });
    }

    /**
     * Handle an incoming registration request (create new user).
     *
     * Open registration is closed: an account can be created only with a valid,
     * unexpired, unused budget invitation for the same email (the new user then
     * joins that budget with the invited role), or for an email in
     * config('auth.registration_allowlist').
     *
     * The invitation or allowlist is checked before the unique-email rule, so a
     * guest without either cannot learn which addresses already have accounts.
     */
    public function registerStore(Request $request, BudgetSharingService $sharing): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'invitation' => 'nullable|string|max:255',
        ]);

        $invitation = null;

        if ($request->filled('invitation')) {
            $invitation = BudgetInvitation::findPendingByToken($request->input('invitation'));

            if (! $invitation) {
                return back()->withErrors([
                    'email' => 'This invitation link is invalid, expired or has already been used.',
                ])->withInput();
            }

            if (! $invitation->matchesEmail($request->email)) {
                return back()->withErrors([
                    'email' => 'Use the email address the invitation was sent to.',
                ])->withInput();
            }
        } elseif (! in_array(strtolower(trim($request->email)), config('auth.registration_allowlist', []), true)) {
            return back()->withErrors([
                'email' => 'Registration is currently restricted. Please contact the administrator.',
            ])->withInput();
        }

        $request->validate(['email' => 'unique:'.User::class]);

        $user = DB::transaction(function () use ($request, $invitation, $sharing) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($invitation) {
                // The invitation link was delivered to this address, which proves ownership.
                $user->forceFill(['email_verified_at' => now()])->save();
                $sharing->accept($invitation, $user);
            }

            return $user;
        });

        Auth::login($user);

        // Redirect to passkey registration page with new_user flag
        return redirect()->route('passkey.register', ['new_user' => true])->with('status', 'Account created! Now register your passkey.');
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
