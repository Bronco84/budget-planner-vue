<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\PlaidConnection;

/**
 * Deletes a budget together with what its rows do not cascade to: polymorphic
 * file attachments (and their unused S3 objects) and the Plaid items at Plaid.
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
        $this->files->deleteBudgetAttachments($budget);

        $budget->delete();

        $plaidConnections->each(fn (PlaidConnection $connection) => $this->plaid->removeConnectionItem($connection));
    }
}
