<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Services\VirtualAccountService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HybridAccountService
{
    public function __construct(
        protected VirtualAccountService $virtualAccountService
    ) {}

    /**
     * Sync Airtable virtual accounts to local Account records
     * This maintains local accounts as synchronized representations of Airtable data
     */
    public function syncAccountsForBudget(Budget $budget): array
    {
        $virtualAccounts = $this->virtualAccountService->getAccountsForBudget($budget);
        
        $synced = 0;
        $created = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($budget, $virtualAccounts, &$synced, &$created, &$updated, &$errors) {
            foreach ($virtualAccounts as $virtualAccount) {
                try {
                    $result = $this->syncSingleAccount($budget, $virtualAccount);
                    
                    if ($result['action'] === 'created') {
                        $created++;
                    } elseif ($result['action'] === 'updated') {
                        $updated++;
                    }
                    $synced++;
                    
                } catch (\Exception $e) {
                    $errors[] = [
                        'airtable_id' => $virtualAccount['airtable_id'],
                        'error' => $e->getMessage()
                    ];
                    Log::warning('Failed to sync virtual account to local account', [
                        'airtable_id' => $virtualAccount['airtable_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });

        Log::info('Hybrid account sync completed', [
            'budget_id' => $budget->id,
            'synced' => $synced,
            'created' => $created,
            'updated' => $updated,
            'errors' => count($errors)
        ]);

        return [
            'synced' => $synced,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ];
    }

    /**
     * Sync a single virtual account to a local Account record
     */
    protected function syncSingleAccount(Budget $budget, array $virtualAccount): array
    {
        // Try to find existing account by airtable_id
        $existingAccount = Account::where('budget_id', $budget->id)
            ->where('airtable_account_id', $virtualAccount['airtable_id'])
            ->first();

        $accountData = [
            'budget_id' => $budget->id,
            'name' => $virtualAccount['name'],
            'type' => $virtualAccount['type'],
            'current_balance_cents' => $virtualAccount['current_balance_cents'],
            'balance_updated_at' => $virtualAccount['balance_updated_at'],
            'include_in_budget' => $virtualAccount['include_in_budget'],
            'airtable_account_id' => $virtualAccount['airtable_id'],
            'airtable_metadata' => $virtualAccount['airtable_metadata'],
            'last_airtable_sync' => now(),
        ];

        if ($existingAccount) {
            // Update existing account
            $existingAccount->update($accountData);
            return ['action' => 'updated', 'account' => $existingAccount];
        } else {
            // Create new account
            $account = Account::create($accountData);
            return ['action' => 'created', 'account' => $account];
        }
    }

    /**
     * Get or create local account for a virtual account
     */
    public function getOrCreateLocalAccount(Budget $budget, string $airtableAccountId): ?Account
    {
        // First try to find existing local account
        $account = Account::where('budget_id', $budget->id)
            ->where('airtable_account_id', $airtableAccountId)
            ->first();

        if ($account) {
            return $account;
        }

        // Get virtual account data
        $virtualAccount = $this->virtualAccountService->getAccount($budget, $airtableAccountId);
        
        if (!$virtualAccount) {
            return null;
        }

        // Create local account
        $result = $this->syncSingleAccount($budget, $virtualAccount);
        return $result['account'];
    }

    /**
     * Get all accounts for a budget (local accounts synchronized with Airtable)
     */
    public function getAccountsForBudget(Budget $budget): Collection
    {
        // Sync accounts first to ensure they're up to date
        $this->syncAccountsForBudget($budget);
        
        // Return local accounts
        return $budget->accounts()
            ->whereNotNull('airtable_account_id') // Only return Airtable-synchronized accounts
            ->orderBy('name')
            ->get();
    }

    /**
     * Get accounts grouped by category hierarchy
     */
    public function getGroupedAccountsForBudget(Budget $budget, ?int $userId = null): Collection
    {
        // Sync accounts first
        $this->syncAccountsForBudget($budget);
        
        // Get virtual accounts grouped
        $groupedVirtual = $this->virtualAccountService->getGroupedAccountsForBudget($budget, $userId);
        
        // Map to local account models
        return $groupedVirtual->map(function ($group) use ($budget) {
            $localAccounts = collect($group['accounts'])->map(function ($virtualAccount) use ($budget) {
                return $budget->accounts()
                    ->where('airtable_account_id', $virtualAccount['airtable_id'])
                    ->first();
            })->filter();
            
            return [
                'name' => $group['name'],
                'id' => $group['id'],
                'accounts' => $localAccounts,
                'total_balance' => $group['total_balance'],
                'account_count' => $localAccounts->count(),
                'group_type' => $group['group_type'],
                'icon' => $group['icon'],
                'collapsed' => $group['collapsed'],
            ];
        });
    }

    /**
     * Get all accounts including legacy accounts
     */
    public function getAllAccountsForBudget(Budget $budget): Collection
    {
        // Sync Airtable accounts
        $this->syncAccountsForBudget($budget);
        
        // Return all accounts
        return $budget->accounts()
            ->orderBy('name')
            ->get();
    }

    /**
     * Update account balance from Airtable
     */
    public function refreshAccountBalance(Account $account): bool
    {
        if (!$account->airtable_account_id) {
            return false; // Not an Airtable-synchronized account
        }

        $virtualAccount = $this->virtualAccountService->getAccount($account->budget, $account->airtable_account_id);
        
        if (!$virtualAccount) {
            return false;
        }

        $account->update([
            'current_balance_cents' => $virtualAccount['current_balance_cents'],
            'balance_updated_at' => $virtualAccount['balance_updated_at'],
            'last_airtable_sync' => now(),
        ]);

        return true;
    }

    /**
     * Mark accounts as inactive if they're no longer in Airtable
     */
    public function markMissingAccountsInactive(Budget $budget): int
    {
        $virtualAccounts = $this->virtualAccountService->getAccountsForBudget($budget);
        $airtableIds = $virtualAccounts->pluck('airtable_id')->toArray();

        // Find local accounts that are no longer in Airtable
        $missingAccounts = Account::where('budget_id', $budget->id)
            ->whereNotNull('airtable_account_id')
            ->whereNotIn('airtable_account_id', $airtableIds)
            ->where('include_in_budget', true)
            ->get();

        foreach ($missingAccounts as $account) {
            $account->update([
                'include_in_budget' => false,
                'last_airtable_sync' => now(),
            ]);
        }

        return $missingAccounts->count();
    }

    /**
     * Get account by either local ID or Airtable ID
     */
    public function getAccount(Budget $budget, $accountIdentifier): ?Account
    {
        // Try local ID first
        $account = Account::where('budget_id', $budget->id)
            ->where('id', $accountIdentifier)
            ->first();

        if ($account) {
            return $account;
        }

        // Try Airtable ID
        $account = Account::where('budget_id', $budget->id)
            ->where('airtable_account_id', $accountIdentifier)
            ->first();

        if ($account) {
            return $account;
        }

        // Try to get/create from Airtable
        return $this->getOrCreateLocalAccount($budget, $accountIdentifier);
    }
}
