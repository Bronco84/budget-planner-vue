<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\Scenario;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes a user account without taking other people's data with it.
 *
 * - A budget the user primarily owns and shares passes to the longest-standing
 *   other owner (BudgetSharingService::leave() succession). When no other owner
 *   exists, deletion is blocked, as leaving would be.
 * - A budget nobody else shares is destroyed with full S3 and Plaid cleanup.
 * - Files, attachments and scenarios the user added to budgets that live on are
 *   kept and reassigned to each budget's primary owner, so the users foreign
 *   key cascades no longer remove them.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly BudgetSharingService $sharing,
        private readonly BudgetDeletionService $budgets,
        private readonly FileService $files,
    ) {}

    /**
     * Check, before anything is changed, that the account can be deleted.
     *
     * @throws ValidationException when the user is the last owner of a shared budget
     */
    public function assertDeletable(User $user): void
    {
        [$shared] = $this->ownedBudgets($user);

        $this->assertEachSharedBudgetHasAnotherOwner($shared);
    }

    /**
     * @throws ValidationException when the user is the last owner of a shared budget
     */
    public function delete(User $user): void
    {
        [$shared, $unshared] = $this->ownedBudgets($user);

        $this->assertEachSharedBudgetHasAnotherOwner($shared);

        // Budgets that survive the user and keep what they added.
        $surviving = $shared->pluck('id')->merge($user->linked_budgets()->pluck('budgets.id'))->unique();

        $unshared->each(fn (Budget $budget) => $this->budgets->destroy($budget));

        DB::transaction(function () use ($user, $shared, $surviving) {
            $shared->each(fn (Budget $budget) => $this->sharing->leave($budget, $user));

            Budget::whereKey($surviving)->get()
                ->each(fn (Budget $budget) => $this->reassignAuthorship($budget, $user));

            $this->reassignOrRemoveFiles($user);

            // Trusted devices, magic-link tokens, preferences and memberships cascade
            // via foreign keys; WebAuthn credentials are polymorphic so are removed here.
            $user->webAuthnCredentials()->delete();
            $user->delete();
        });
    }

    /**
     * The budgets the user primarily owns, split into shared and unshared.
     *
     * @return array{0: Collection<int, Budget>, 1: Collection<int, Budget>}
     */
    private function ownedBudgets(User $user): array
    {
        [$shared, $unshared] = $user->budgets()->withCount('connected_users')->get()
            ->partition(fn (Budget $budget) => $budget->connected_users_count > 0);

        return [$shared->values(), $unshared->values()];
    }

    /**
     * @param  Collection<int, Budget>  $shared
     */
    private function assertEachSharedBudgetHasAnotherOwner(Collection $shared): void
    {
        $orphaned = $shared->filter(fn (Budget $budget) => ! $budget->connected_users()
            ->wherePivotIn('access_level', ['owner', 'admin'])
            ->exists());

        if ($orphaned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'email' => 'You are the last owner of '.$orphaned->pluck('name')->map(fn ($name) => "\"{$name}\"")->join(', ', ' and ')
                    .'. Make another member an owner, or delete the budget, before deleting your account.',
            ]);
        }
    }

    /**
     * Hand the user's scenarios and attachments on a surviving budget to its primary owner.
     */
    private function reassignAuthorship(Budget $budget, User $user): void
    {
        $ownerId = $budget->user_id;

        Scenario::where('budget_id', $budget->id)
            ->where('user_id', $user->id)
            ->update(['user_id' => $ownerId]);

        FileAttachment::query()
            ->where('attached_by', $user->id)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('attachable_type', Budget::class)
                    ->where('attachable_id', $budget->id))
                ->orWhere(fn ($query) => $query
                    ->where('attachable_type', Transaction::class)
                    ->whereIn('attachable_id', $budget->transactions()->select('id'))))
            ->update(['attached_by' => $ownerId]);
    }

    /**
     * Files the user uploaded that are still attached somewhere pass to the primary
     * owner of the budget they are attached to (merged into the owner's own record
     * for identical content). Files attached nowhere are removed with their S3 object.
     */
    private function reassignOrRemoveFiles(User $user): void
    {
        File::where('uploaded_by', $user->id)->with('attachments.attachable')->get()
            ->each(function (File $file) use ($user) {
                $ownerId = $file->attachments
                    ->map(fn (FileAttachment $attachment) => $this->files->attachmentBudget($attachment)?->user_id)
                    ->first(fn ($id) => $id !== null && (int) $id !== (int) $user->id);

                if ($ownerId === null) {
                    $this->removeUnusedFile($file);

                    return;
                }

                $ownersCopy = File::where('hash', $file->hash)->where('uploaded_by', $ownerId)->first();

                if (! $ownersCopy) {
                    File::whereKey($file->id)->update(['uploaded_by' => $ownerId]);

                    return;
                }

                // The owner already has a record for the same content (and S3 object).
                // Where it is already attached to the same item, the duplicate goes.
                foreach ($file->attachments as $attachment) {
                    $alreadyAttached = FileAttachment::where('file_id', $ownersCopy->id)
                        ->where('attachable_type', $attachment->attachable_type)
                        ->where('attachable_id', $attachment->attachable_id)
                        ->exists();

                    $alreadyAttached
                        ? FileAttachment::whereKey($attachment->id)->delete()
                        : FileAttachment::whereKey($attachment->id)->update(['file_id' => $ownersCopy->id]);
                }
                $this->removeUnusedFile($file);
            });
    }

    private function removeUnusedFile(File $file): void
    {
        FileAttachment::where('file_id', $file->id)->delete();

        try {
            if ($file->deleteFromS3IfUnused()) {
                $file->delete();
                Activity::query()->whereMorphedTo('subject', $file)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to remove an unused file while deleting an account', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
