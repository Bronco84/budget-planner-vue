<?php

namespace App\Services;

use App\Enums\BudgetRole;
use App\Mail\BudgetInvitationMail;
use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Budget sharing: invitations, member roles, leaving and ownership transfer.
 *
 * Invariants:
 * - budgets.user_id is the primary owner and always has the owner role; they
 *   are never a row in budget_user, cannot be removed or downgraded, and leave
 *   only by handing primary ownership to another owner.
 * - every budget therefore always has at least one owner.
 */
class BudgetSharingService
{
    /**
     * Invite an email address to the budget with a role and email them a link.
     * A pending invitation for the same email is replaced (new token and expiry).
     *
     * @return array{invitation: BudgetInvitation, mailed: bool}
     *
     * @throws ValidationException when the email already belongs to a member
     */
    public function invite(Budget $budget, User $inviter, string $email, BudgetRole $role): array
    {
        $email = Str::lower(trim($email));
        $existingUser = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existingUser && $budget->roleFor($existingUser) !== null) {
            throw ValidationException::withMessages([
                'email' => 'That person is already a member of this budget.',
            ]);
        }

        $token = BudgetInvitation::generateToken();

        $invitation = DB::transaction(function () use ($budget, $inviter, $email, $role, $token) {
            $budget->invitations()->pending()->whereRaw('LOWER(email) = ?', [$email])->delete();

            return $budget->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => BudgetInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(BudgetInvitation::EXPIRY_DAYS),
            ]);
        });

        $mailed = true;
        try {
            Mail::to($email)->send(new BudgetInvitationMail(
                $invitation->load(['budget', 'inviter']),
                route('invitations.show', $token),
                $existingUser !== null,
            ));
        } catch (\Throwable $e) {
            $mailed = false;
            Log::error('Failed to send budget invitation email', [
                'budget_id' => $budget->id,
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['invitation' => $invitation, 'mailed' => $mailed];
    }

    /**
     * Accept a pending invitation as $user, who must own the invited email.
     * Single use: the invitation is claimed atomically, so a replayed or
     * concurrent accept fails.
     *
     * @throws ValidationException
     */
    public function accept(BudgetInvitation $invitation, User $user): Budget
    {
        if (! $invitation->matchesEmail($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation was sent to a different email address.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $user) {
            $claimed = BudgetInvitation::whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is invalid, expired or has already been used.',
                ]);
            }

            /** @var Budget $budget */
            $budget = $invitation->budget()->firstOrFail();

            // An invitation is only as good as its inviter's standing: once
            // they stop owning the budget, what they issued is void.
            $inviter = $invitation->inviter()->first();
            if ($budget->roleFor($inviter) !== BudgetRole::Owner) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is invalid, expired or has already been used.',
                ]);
            }

            $current = $budget->roleFor($user);

            // Never downgrade someone who already has a stronger role.
            if ($current === null) {
                $budget->connected_users()->attach($user->id, ['access_level' => $invitation->role->value]);
            } elseif (! $budget->isPrimaryOwner($user) && $this->rank($invitation->role) > $this->rank($current)) {
                $budget->connected_users()->updateExistingPivot($user->id, ['access_level' => $invitation->role->value]);
            }

            $user->setActiveBudget($budget->id);

            return $budget;
        });
    }

    /**
     * Change a shared member's role. The primary owner is not a pivot member,
     * so they can never be downgraded here and an owner always remains.
     */
    public function changeRole(Budget $budget, User $member, BudgetRole $role): void
    {
        $this->assertSharedMember($budget, $member);

        DB::transaction(function () use ($budget, $member, $role) {
            $budget->connected_users()->updateExistingPivot($member->id, ['access_level' => $role->value]);

            if ($role !== BudgetRole::Owner) {
                $this->revokeInvitationsIssuedBy($budget, $member);
            }
        });
    }

    /**
     * Remove a shared member. Their stale active-budget preference falls back
     * on their next request (User::getActiveBudget()).
     */
    public function removeMember(Budget $budget, User $member): void
    {
        $this->assertSharedMember($budget, $member);

        DB::transaction(function () use ($budget, $member) {
            $budget->connected_users()->detach($member->id);
            $this->revokeInvitationsIssuedBy($budget, $member);
        });
    }

    /**
     * $user leaves the budget. A primary owner may leave only when another owner
     * exists; primary ownership then passes to the longest-standing owner.
     *
     * @throws ValidationException when $user is the last owner
     */
    public function leave(Budget $budget, User $user): void
    {
        DB::transaction(function () use ($budget, $user) {
            if (! $budget->isPrimaryOwner($user)) {
                $budget->connected_users()->detach($user->id);
                $this->revokeInvitationsIssuedBy($budget, $user);

                return;
            }

            $successor = $budget->connected_users()
                ->wherePivotIn('access_level', ['owner', 'admin'])
                ->orderBy('budget_user.created_at')
                ->orderBy('budget_user.id')
                ->first();

            if (! $successor) {
                throw ValidationException::withMessages([
                    'membership' => 'You are the last owner of this budget. Make another member an owner, or delete the budget instead.',
                ]);
            }

            $budget->connected_users()->detach($successor->id);
            $budget->forceFill(['user_id' => $successor->id])->save();
            $this->revokeInvitationsIssuedBy($budget, $user);
        });
    }

    /**
     * Hand primary ownership to another member. The new primary owner leaves
     * the pivot; the previous one stays on as an owner member.
     */
    public function transferOwnership(Budget $budget, User $from, User $to): void
    {
        $this->assertSharedMember($budget, $to);

        DB::transaction(function () use ($budget, $from, $to) {
            $budget->connected_users()->detach($to->id);
            $budget->forceFill(['user_id' => $to->id])->save();
            $budget->connected_users()->syncWithoutDetaching([
                $from->id => ['access_level' => BudgetRole::Owner->value],
            ]);
        });
    }

    /**
     * Members (including the primary owner first) for display.
     *
     * @return list<array<string, mixed>>
     */
    public function membersFor(Budget $budget, User $viewer): array
    {
        $owner = $budget->user()->first();

        $members = [];
        if ($owner) {
            $members[] = [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => BudgetRole::Owner->value,
                'is_primary_owner' => true,
                'is_you' => $owner->id === $viewer->id,
                'joined_at' => $budget->created_at?->toIso8601String(),
            ];
        }

        $budget->connected_users()->orderBy('budget_user.created_at')->get()
            ->each(function (User $member) use (&$members, $viewer) {
                $members[] = [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => BudgetRole::fromStored($member->pivot->access_level)->value,
                    'is_primary_owner' => false,
                    'is_you' => $member->id === $viewer->id,
                    'joined_at' => $member->pivot->created_at?->toIso8601String(),
                ];
            });

        return $members;
    }

    private function assertSharedMember(Budget $budget, User $member): void
    {
        if ($budget->isPrimaryOwner($member)) {
            throw ValidationException::withMessages([
                'member' => 'The primary owner cannot be changed or removed. Transfer ownership first.',
            ]);
        }

        if (! $budget->connected_users()->where('users.id', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'That person is not a member of this budget.',
            ]);
        }
    }

    /**
     * Delete the pending invitations $user issued for $budget, so a grant
     * cannot outlive the inviter's ownership.
     */
    private function revokeInvitationsIssuedBy(Budget $budget, User $user): void
    {
        $budget->invitations()->pending()->where('invited_by', $user->id)->delete();
    }

    private function rank(BudgetRole $role): int
    {
        return match ($role) {
            BudgetRole::Viewer => 1,
            BudgetRole::Editor => 2,
            BudgetRole::Owner => 3,
        };
    }
}
