<?php

namespace App\Http\Controllers;

use App\Enums\BudgetRole;
use App\Models\Budget;
use App\Models\User;
use App\Services\BudgetSharingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Budget sharing page and member management. Any member can see who has
 * access and leave; only owners manage members (BudgetPolicy::manageMembers)
 * and only the primary owner transfers primary ownership.
 */
class BudgetSharingController extends Controller
{
    public function __construct(private BudgetSharingService $sharing) {}

    public function index(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $user = $request->user();
        $canManage = $user->can('manageMembers', $budget);

        return Inertia::render('Budgets/Sharing', [
            'budget' => ['id' => $budget->id, 'name' => $budget->name],
            'role' => $budget->roleFor($user)?->value,
            'isPrimaryOwner' => $budget->isPrimaryOwner($user),
            'members' => $this->sharing->membersFor($budget, $user),
            'invitations' => $canManage
                ? $budget->invitations()->pending()->with('inviter')->latest()->get()->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])->values()
                : [],
            'roles' => array_map(fn (BudgetRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], BudgetRole::cases()),
            'can' => [
                'manage' => $canManage,
                'transfer' => $user->can('transferOwnership', $budget),
            ],
        ]);
    }

    public function update(Request $request, Budget $budget, User $member): RedirectResponse
    {
        $this->authorize('manageMembers', $budget);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(BudgetRole::class)],
        ]);

        $this->sharing->changeRole($budget, $member, BudgetRole::from($validated['role']));

        return back()->with('message', "{$member->name} is now {$this->article($validated['role'])} {$validated['role']}.");
    }

    public function destroy(Budget $budget, User $member): RedirectResponse
    {
        $this->authorize('manageMembers', $budget);

        $this->sharing->removeMember($budget, $member);

        return back()->with('message', "{$member->name} no longer has access to this budget.");
    }

    public function transferOwnership(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('transferOwnership', $budget);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $member = $budget->connected_users()->where('users.id', $validated['user_id'])->first();

        if (! $member) {
            return back()->withErrors(['user_id' => 'Choose a current member of this budget.']);
        }

        $this->sharing->transferOwnership($budget, $request->user(), $member);

        return back()->with('message', "{$member->name} is now the primary owner of this budget.");
    }

    /**
     * Leave a shared budget. Any member may do this (the route opts out of the
     * editor-only write middleware), except the last owner.
     */
    public function leave(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('view', $budget);

        $this->sharing->leave($budget, $request->user());

        return redirect()->route('budgets.index')->with('message', "You left the budget \"{$budget->name}\".");
    }

    private function article(string $role): string
    {
        return $role === 'editor' || $role === 'owner' ? 'an' : 'a';
    }
}
