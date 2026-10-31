<?php

namespace App\Http\Controllers;

use App\Enums\BudgetRole;
use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\User;
use App\Services\BudgetSharingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inviting people to a budget (owners) and the invitee's side of the flow:
 * the emailed link (invitations.show) and accepting while signed in. People
 * without an account register through /register?invitation=<token>.
 */
class BudgetInvitationController extends Controller
{
    /**
     * Session key holding the invitation URL a guest opened, so that after
     * signing in budgets.index returns them to it.
     */
    public const PENDING_SESSION_KEY = 'budget_invitation.pending_url';

    public function __construct(private BudgetSharingService $sharing) {}

    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('manageMembers', $budget);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(BudgetRole::class)],
        ]);

        $result = $this->sharing->invite($budget, $request->user(), $validated['email'], BudgetRole::from($validated['role']));

        if (! $result['mailed']) {
            return back()->with('error', 'The invitation was created, but the email could not be sent. Try again later.');
        }

        return back()->with('message', "Invitation sent to {$result['invitation']->email}.");
    }

    public function destroy(Budget $budget, BudgetInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageMembers', $budget);

        $invitation->delete();

        return back()->with('message', "Invitation for {$invitation->email} revoked.");
    }

    /**
     * Landing page for the emailed link. Works signed in or out.
     */
    public function show(Request $request, string $token): Response
    {
        $invitation = BudgetInvitation::findPendingByToken($token);
        $user = $request->user();

        if (! $invitation) {
            return Inertia::render('Invitations/Show', [
                'status' => 'invalid',
                'invitation' => null,
                'token' => null,
            ]);
        }

        $invitation->load(['budget', 'inviter']);

        if ($user) {
            $status = $invitation->matchesEmail($user->email) ? 'ready' : 'wrong_account';
        } else {
            // Return here after signing in (see BudgetController::index).
            $request->session()->put(self::PENDING_SESSION_KEY, $request->fullUrl());
            $status = User::whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists()
                ? 'sign_in'
                : 'register';
        }

        return Inertia::render('Invitations/Show', [
            'status' => $status,
            'token' => $token,
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'budget_name' => $invitation->budget->name,
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'signedInAs' => $user?->email,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = BudgetInvitation::findPendingByToken($token);

        if (! $invitation) {
            return redirect()->route('invitations.show', $token)
                ->with('error', 'This invitation is invalid, expired or has already been used.');
        }

        $budget = $this->sharing->accept($invitation, $request->user());
        $request->session()->forget(self::PENDING_SESSION_KEY);

        return redirect()->route('budgets.show', $budget)
            ->with('message', "You joined \"{$budget->name}\" as {$this->article($invitation->role)} {$invitation->role->value}.");
    }

    private function article(BudgetRole $role): string
    {
        return $role === BudgetRole::Viewer ? 'a' : 'an';
    }
}
