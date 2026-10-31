<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Budget authorization by sharing role (see App\Enums\BudgetRole):
 *
 * - view                         any member (viewer, editor, owner)
 * - update                       editor or owner: create/update/delete budget DATA
 *                                (transactions, accounts, categories, transfers,
 *                                recurring templates & rules, scenarios, payoff
 *                                plans, properties, files) and trigger bank syncs
 * - updateSettings, delete,      owner only: budget name/settings, deletion,
 *   manageMembers,               inviting and managing members, and connecting,
 *   manageConnections            re-authenticating or unlinking bank connections
 * - transferOwnership            the primary owner only
 *
 * Every rule resolves the role through Budget::roleFor(), the single source of truth.
 */
class BudgetPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->canViewBudget($budget);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Write access to the budget's data (editor or owner).
     */
    public function update(User $user, Budget $budget): bool
    {
        return $user->canEditBudget($budget);
    }

    /**
     * Budget name, description and colour.
     */
    public function updateSettings(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }

    /**
     * Invite, re-role and remove members; revoke invitations.
     */
    public function manageMembers(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }

    /**
     * Connect, re-authenticate and unlink bank (Plaid) connections.
     */
    public function manageConnections(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }

    /**
     * Refresh data from existing bank connections (editor or owner).
     */
    public function sync(User $user, Budget $budget): bool
    {
        return $user->canEditBudget($budget);
    }

    public function transferOwnership(User $user, Budget $budget): bool
    {
        return $budget->isPrimaryOwner($user);
    }

    public function restore(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }

    public function forceDelete(User $user, Budget $budget): bool
    {
        return $user->ownsBudget($budget);
    }
}
