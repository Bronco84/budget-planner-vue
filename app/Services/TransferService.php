<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransferService
{
    /**
     * Create a new transfer with its paired transactions.
     *
     * @param array $data Transfer data (budget_id, from_account_id, to_account_id, amount_in_cents, date, description, notes)
     * @return Transfer
     */
    public function create(array $data): Transfer
    {
        return DB::transaction(function () use ($data) {
            // Create the transfer (source of truth)
            $transfer = Transfer::create([
                'budget_id' => $data['budget_id'],
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => $data['to_account_id'],
                'amount_in_cents' => abs($data['amount_in_cents']), // Always store as positive
                'date' => $data['date'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create the paired transactions
            $this->createPairedTransactions($transfer);

            return $transfer->load(['fromAccount', 'toAccount', 'transactions']);
        });
    }

    /**
     * Update a transfer and sync its paired transactions.
     *
     * @param Transfer $transfer
     * @param array $data
     * @return Transfer
     */
    public function update(Transfer $transfer, array $data): Transfer
    {
        return DB::transaction(function () use ($transfer, $data) {
            // Update the transfer
            $transfer->update([
                'from_account_id' => $data['from_account_id'] ?? $transfer->from_account_id,
                'to_account_id' => $data['to_account_id'] ?? $transfer->to_account_id,
                'amount_in_cents' => isset($data['amount_in_cents']) ? abs($data['amount_in_cents']) : $transfer->amount_in_cents,
                'date' => $data['date'] ?? $transfer->date,
                'description' => $data['description'] ?? $transfer->description,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transfer->notes,
            ]);

            // Sync the paired transactions
            $this->syncPairedTransactions($transfer);

            return $transfer->fresh(['fromAccount', 'toAccount', 'transactions']);
        });
    }

    /**
     * Delete a transfer and its paired transactions.
     *
     * @param Transfer $transfer
     * @return bool
     */
    public function delete(Transfer $transfer): bool
    {
        return DB::transaction(function () use ($transfer) {
            // Delete the paired transactions first
            $transfer->transactions()->delete();

            // Delete the transfer
            return $transfer->delete();
        });
    }

    /**
     * Create the paired transactions for a transfer.
     *
     * @param Transfer $transfer
     * @return void
     */
    protected function createPairedTransactions(Transfer $transfer): void
    {
        $description = $transfer->description ?? $transfer->default_description;

        // Transaction for the source account (negative - money leaving)
        Transaction::create([
            'budget_id' => $transfer->budget_id,
            'account_id' => $transfer->from_account_id,
            'description' => $description,
            'category' => 'Transfer',
            'amount_in_cents' => -abs($transfer->amount_in_cents), // Negative for outgoing
            'date' => $transfer->date,
            'transfer_id' => $transfer->id,
            'import_source' => 'manual',
        ]);

        // Transaction for the destination account (positive - money entering)
        Transaction::create([
            'budget_id' => $transfer->budget_id,
            'account_id' => $transfer->to_account_id,
            'description' => $description,
            'category' => 'Transfer',
            'amount_in_cents' => abs($transfer->amount_in_cents), // Positive for incoming
            'date' => $transfer->date,
            'transfer_id' => $transfer->id,
            'import_source' => 'manual',
        ]);
    }

    /**
     * Sync the paired transactions when a transfer is updated.
     *
     * @param Transfer $transfer
     * @return void
     */
    protected function syncPairedTransactions(Transfer $transfer): void
    {
        $description = $transfer->description ?? $transfer->default_description;
        $transactions = $transfer->transactions;

        // Find or identify the from/to transactions
        $fromTransaction = $transactions->firstWhere('amount_in_cents', '<', 0);
        $toTransaction = $transactions->firstWhere('amount_in_cents', '>', 0);

        // Update or create from transaction
        if ($fromTransaction) {
            $fromTransaction->update([
                'account_id' => $transfer->from_account_id,
                'description' => $description,
                'amount_in_cents' => -abs($transfer->amount_in_cents),
                'date' => $transfer->date,
            ]);
        } else {
            Transaction::create([
                'budget_id' => $transfer->budget_id,
                'account_id' => $transfer->from_account_id,
                'description' => $description,
                'category' => 'Transfer',
                'amount_in_cents' => -abs($transfer->amount_in_cents),
                'date' => $transfer->date,
                'transfer_id' => $transfer->id,
                'import_source' => 'manual',
            ]);
        }

        // Update or create to transaction
        if ($toTransaction) {
            $toTransaction->update([
                'account_id' => $transfer->to_account_id,
                'description' => $description,
                'amount_in_cents' => abs($transfer->amount_in_cents),
                'date' => $transfer->date,
            ]);
        } else {
            Transaction::create([
                'budget_id' => $transfer->budget_id,
                'account_id' => $transfer->to_account_id,
                'description' => $description,
                'category' => 'Transfer',
                'amount_in_cents' => abs($transfer->amount_in_cents),
                'date' => $transfer->date,
                'transfer_id' => $transfer->id,
                'import_source' => 'manual',
            ]);
        }
    }

    /**
     * Get all future (projected) transfers for a budget.
     *
     * @param Budget $budget
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return Collection
     */
    public function getFutureTransfers(Budget $budget, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $startDate = $startDate ?? now()->addDay()->startOfDay();
        $endDate = $endDate ?? now()->addMonths(6)->endOfMonth();

        return Transfer::where('budget_id', $budget->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->with(['fromAccount', 'toAccount'])
            ->orderBy('date')
            ->get();
    }

    /**
     * Get projected transfer transactions for an account.
     * Returns array of transaction-like data for inclusion in projections.
     *
     * @param Account $account
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection
     */
    public function getProjectedTransferTransactions(Account $account, Carbon $startDate, Carbon $endDate): Collection
    {
        // Get transfers where this account is either source or destination
        $transfers = Transfer::where('budget_id', $account->budget_id)
            ->where(function ($query) use ($account) {
                $query->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->whereBetween('date', [$startDate, $endDate])
            ->with(['fromAccount', 'toAccount'])
            ->get();

        return $transfers->map(function (Transfer $transfer) use ($account) {
            // Determine if this account is source or destination
            $isSource = $transfer->from_account_id === $account->id;

            return [
                'budget_id' => $transfer->budget_id,
                'account_id' => $account->id,
                'description' => $transfer->description ?? $transfer->default_description,
                'category' => 'Transfer',
                'amount_in_cents' => $isSource ? -abs($transfer->amount_in_cents) : abs($transfer->amount_in_cents),
                'date' => $transfer->date,
                'transfer_id' => $transfer->id,
                'is_projected' => true,
                'is_transfer' => true,
                'projection_source' => 'transfer',
                'transfer_from_account' => $transfer->fromAccount?->name,
                'transfer_to_account' => $transfer->toAccount?->name,
            ];
        });
    }

    /**
     * Get all transfers for a budget with pagination.
     *
     * @param Budget $budget
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getTransfersForBudget(Budget $budget, int $perPage = 25)
    {
        return Transfer::where('budget_id', $budget->id)
            ->with(['fromAccount', 'toAccount'])
            ->orderByDesc('date')
            ->paginate($perPage);
    }
}
