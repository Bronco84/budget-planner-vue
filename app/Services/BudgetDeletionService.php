<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes a budget together with what its rows do not cascade to: polymorphic
 * file attachments (and their unused S3 objects), the Plaid items at Plaid, and
 * its activity-log history (which copies transaction descriptions, amounts and
 * notes, and file names). Activity on live budgets is kept indefinitely.
 * Used by budget deletion and by account deletion for budgets nobody else shares.
 */
class BudgetDeletionService
{
    public function __construct(
        private readonly FileService $files,
        private readonly PlaidService $plaid,
    ) {}

    public function destroy(Budget $budget): void
    {
        // Neither has a database cascade: polymorphic attachments, and items at Plaid.
        $plaidConnections = PlaidConnection::where('budget_id', $budget->id)->get();
        [$attachmentIds, $fileIds] = $this->attachmentHistory($budget);

        $this->files->deleteBudgetAttachments($budget);

        DB::transaction(function () use ($budget, $attachmentIds, $fileIds) {
            $this->eraseActivity($budget, $attachmentIds, $fileIds);
            $budget->delete();
        });

        $plaidConnections->each(fn (PlaidConnection $connection) => $this->plaid->removeConnectionItem($connection));
    }

    /**
     * Every attachment ever made on the budget or its transactions (current ones,
     * and detached ones known from their activity rows), and the files they used.
     *
     * @return array{0: Collection<int, int>, 1: Collection<int, int>}
     */
    private function attachmentHistory(Budget $budget): array
    {
        $current = FileAttachment::query()
            ->where(fn (Builder $query) => $this->attachedToBudget($query, $budget, 'attachable_type', 'attachable_id'))
            ->get(['id', 'file_id']);

        $logged = Activity::query()
            ->where('subject_type', FileAttachment::class)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $this->attachedToBudget($query, $budget, 'properties->attributes->attachable_type', 'properties->attributes->attachable_id'))
                ->orWhere(fn (Builder $query) => $this->attachedToBudget($query, $budget, 'properties->attachable_type', 'properties->attachable_id')))
            ->get(['id', 'subject_id', 'properties']);

        $attachmentIds = $current->pluck('id')
            ->merge($logged->pluck('subject_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values();

        $fileIds = $current->pluck('file_id')
            ->merge($logged->map(fn (Activity $activity) => $activity->properties['attributes']['file_id'] ?? null))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()->values();

        return [$attachmentIds, $fileIds];
    }

    /**
     * Delete activity whose subject is the budget, one of its transactions or one
     * of its attachments, and of its files that no longer exist (a file still used
     * by another budget keeps its history).
     *
     * @param  Collection<int, int>  $attachmentIds
     * @param  Collection<int, int>  $fileIds
     */
    private function eraseActivity(Budget $budget, Collection $attachmentIds, Collection $fileIds): void
    {
        Activity::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('subject_type', Budget::class)
                    ->where('subject_id', $budget->id))
                ->orWhere(fn (Builder $query) => $query
                    ->where('subject_type', Transaction::class)
                    ->whereIn('subject_id', $budget->transactions()->select('id'))))
            ->delete();

        $attachmentIds->chunk(500)->each(fn (Collection $ids) => Activity::query()
            ->where('subject_type', FileAttachment::class)
            ->whereIn('subject_id', $ids->all())
            ->delete());

        $removedFileIds = $fileIds->diff(File::whereKey($fileIds->all())->pluck('id'));

        $removedFileIds->chunk(500)->each(fn (Collection $ids) => Activity::query()
            ->where('subject_type', File::class)
            ->whereIn('subject_id', $ids->all())
            ->delete());
    }

    /**
     * Constrain $query to rows whose type/id columns point at the budget or one of its transactions.
     */
    private function attachedToBudget(Builder $query, Budget $budget, string $typeColumn, string $idColumn): Builder
    {
        return $query
            ->where(fn (Builder $query) => $query
                ->where($typeColumn, Budget::class)
                ->where($idColumn, $budget->id))
            ->orWhere(fn (Builder $query) => $query
                ->where($typeColumn, Transaction::class)
                ->whereIn($idColumn, $budget->transactions()->select('id')));
    }
}
