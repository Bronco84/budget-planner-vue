<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\AirtableService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class VirtualAccountService
{
    public function __construct(
        protected AirtableService $airtableService
    ) {}

    /**
     * Get all accounts for a budget from Airtable
     */
    public function getAccountsForBudget(Budget $budget): Collection
    {
        if (!$this->airtableService->isConfigured()) {
            return collect([]);
        }

        // Cache for 12 hours to align with transaction caching  
        return Cache::remember("budget_{$budget->id}_airtable_accounts", 43200, function () use ($budget) {
            $airtableAccounts = $this->airtableService->getAccounts();
            
            return $airtableAccounts->map(function ($record) use ($budget) {
                return $this->transformAirtableRecord($record, $budget);
            })->filter(); // Remove any null results
        });
    }

    /**
     * Get account inclusion preferences for a user
     */
    public function getAccountInclusionPreferences(int $userId): array
    {
        return \App\Models\UserPreference::get($userId, 'account_inclusions', []);
    }

    /**
     * Set account inclusion preference
     */
    public function setAccountInclusion(int $userId, mixed $accountId, bool $included): void
    {
        $inclusions = $this->getAccountInclusionPreferences($userId);
        // Ensure account ID is always stored as string to avoid type issues with JSON
        $inclusions[(string)$accountId] = $included;
        \App\Models\UserPreference::set($userId, 'account_inclusions', $inclusions);
    }

    /**
     * Check if an account should be included in total balance calculation
     */
    public function isAccountIncluded(int $userId, mixed $accountId, string $groupType = null): bool
    {
        $inclusions = $this->getAccountInclusionPreferences($userId);
        
        // Ensure account ID is always treated as string for consistent lookups
        $accountIdStr = (string)$accountId;
        
        // If no preference set, use smart defaults based on account type
        if (!isset($inclusions[$accountIdStr])) {
            return $this->getDefaultInclusionForAccountType($groupType);
        }
        
        return $inclusions[$accountIdStr];
    }

    /**
     * Get default inclusion based on account type
     */
    protected function getDefaultInclusionForAccountType(?string $groupType): bool
    {
        // By default, include assets (checking, savings) but exclude liabilities (credit cards, loans)
        return match($groupType) {
            'banking' => true,      // Banking & Savings accounts
            'investment' => true,   // Investment accounts
            'credit' => false,      // Credit Cards - don't include debt in total balance by default
            'debt' => false,        // Loans & Mortgages - don't include debt in total balance by default
            default => true
        };
    }

    /**
     * Calculate total included balance across all accounts for a budget
     */
    public function getTotalIncludedBalance(Budget $budget, int $userId): int
    {
        $accounts = $this->getAccountsForBudget($budget);
        
        return $accounts->filter(function ($account) use ($userId) {
            $groupType = $this->getGroupType($this->getAccountGroup($account));
            return $this->isAccountIncluded($userId, $account['id'], $groupType);
        })->sum('current_balance_cents');
    }

    /**
     * Get accounts grouped by category hierarchy
     */
    public function getGroupedAccountsForBudget(Budget $budget, ?int $userId = null): Collection
    {
        $accounts = $this->getAccountsForBudget($budget);
        
        $groups = $accounts->groupBy(function ($account) {
            return $this->getAccountGroup($account);
        })->map(function ($groupAccounts, $groupName) use ($userId) {
            $groupType = $this->getGroupType($groupName);
            
            // Add inclusion preference to each account
            $accountsWithInclusion = $groupAccounts->map(function ($account) use ($userId, $groupType) {
                $account['included_in_total'] = $userId 
                    ? $this->isAccountIncluded($userId, $account['id'], $groupType)
                    : $this->getDefaultInclusionForAccountType($groupType);
                return $account;
            });
            
            $totalBalance = $accountsWithInclusion->sum('current_balance_cents');
            $includedBalance = $accountsWithInclusion
                ->where('included_in_total', true)
                ->sum('current_balance_cents');
            
            return [
                'name' => $groupName,
                'accounts' => $accountsWithInclusion->sortBy(['type', 'name'])->values()->toArray(),
                'total_balance' => $totalBalance,
                'included_balance' => $includedBalance,
                'account_count' => $accountsWithInclusion->count(),
                'group_type' => $groupType,
                'icon' => $this->getGroupIcon($groupName),
                'id' => \Str::slug($groupName), // For drag/drop identification
                'collapsed' => $userId ? $this->getGroupCollapsedState($userId, $groupName) : false,
            ];
        });

        // Apply custom ordering if user preferences exist
        if ($userId) {
            $customOrder = $this->getGroupOrdering($userId);
            if (!empty($customOrder)) {
                return $this->applyCustomOrdering($groups, $customOrder);
            }
        }

        // Default ordering
        return $groups->sortBy([
            fn($group) => $this->getGroupPriority($group['name']),
            'name'
        ]);
    }

    /**
     * Get a specific account by Airtable ID
     */
    public function getAccount(Budget $budget, string $airtableId): ?array
    {
        $accounts = $this->getAccountsForBudget($budget);
        return $accounts->firstWhere('airtable_id', $airtableId);
    }

    /**
     * Get account by local transaction account ID (for backward compatibility)
     */
    public function getAccountByLocalId(Budget $budget, int $localAccountId): ?array
    {
        // Check if we have any transactions that link to this local account
        $sampleTransaction = Transaction::where('account_id', $localAccountId)
            ->whereNotNull('airtable_account_id')
            ->first();

        if ($sampleTransaction && $sampleTransaction->airtable_account_id) {
            return $this->getAccount($budget, $sampleTransaction->airtable_account_id);
        }

        return null;
    }

    /**
     * Transform Airtable record into standardized account format
     */
    protected function transformAirtableRecord(array $record, Budget $budget): ?array
    {
        $fields = $record['fields'] ?? [];
        
        // Skip if required fields are missing
        if (empty($fields['*Name'])) {
            return null;
        }

        return [
            'id' => $this->generateLocalId($record['id']), // Generate consistent local ID
            'airtable_id' => $record['id'],
            'budget_id' => $budget->id,
            'name' => $fields['*Name'] ?? 'Unknown Account',
            'type' => $this->mapAccountType($this->parseAccountType($fields)),
            'institution_name' => $fields['**Institution'] ?? null,
            'account_subtype' => $this->parseAccountSubtype($fields),
            'current_balance_cents' => $this->convertToCents($fields['**USD'] ?? 0),
            'available_balance_cents' => $this->parseAvailableBalance($fields),
            'balance_updated_at' => Carbon::parse($record['createdTime'] ?? now()),
            'include_in_budget' => $fields['include_in_budget'] ?? true,
            'is_active' => $fields['is_active'] ?? true,
            'account_number_last_4' => $fields['account_number_last_4'] ?? null,
            'routing_number' => $fields['routing_number'] ?? null,
            'external_account_id' => $fields['external_account_id'] ?? $fields['account_id'] ?? null,
            'fintable_metadata' => $fields,
            'airtable_metadata' => $record,
            'last_sync_at' => Carbon::parse($record['createdTime'] ?? now()),
            
            // Additional computed fields
            'transaction_count' => $this->getTransactionCount($record['id']),
            'status_label' => $this->getStatusLabel($fields),
            'status_classes' => $this->getStatusClasses($fields),
        ];
    }

    /**
     * Generate consistent local ID from Airtable ID
     */
    protected function generateLocalId(string $airtableId): int
    {
        // Create a consistent numeric ID from the Airtable string ID
        return abs(crc32($airtableId));
    }

    /**
     * Map Airtable account types to our standard types
     */
    protected function mapAccountType(string $type): string
    {
        $typeMap = [
            'checking' => 'checking',
            'savings' => 'savings',
            'credit' => 'credit',
            'credit_card' => 'credit',
            'investment' => 'investment',
            'brokerage' => 'investment',
            'loan' => 'other',
            'mortgage' => 'other',
        ];

        return $typeMap[strtolower($type)] ?? 'other';
    }

    /**
     * Convert dollar amounts to cents
     */
    protected function convertToCents(?float $amount): int
    {
        return $amount ? round($amount * 100) : 0;
    }

    /**
     * Get transaction count for this Airtable account
     */
    protected function getTransactionCount(string $airtableId): int
    {
        return Transaction::where('airtable_account_id', $airtableId)->count();
    }

    /**
     * Get status label for account
     */
    protected function getStatusLabel(array $fields): string
    {
        if (!($fields['is_active'] ?? true)) {
            return 'Inactive';
        }
        
        if (!($fields['include_in_budget'] ?? true)) {
            return 'Excluded from Budget';
        }
        
        return 'Active';
    }

    /**
     * Get status CSS classes for account
     */
    protected function getStatusClasses(array $fields): string
    {
        if (!($fields['is_active'] ?? true)) {
            return 'text-red-600 bg-red-50';
        }
        
        if (!($fields['include_in_budget'] ?? true)) {
            return 'text-yellow-600 bg-yellow-50';
        }
        
        return 'text-green-600 bg-green-50';
    }

    /**
     * Get all transactions for an Airtable account
     */
    public function getTransactionsForAccount(string $airtableId): Collection
    {
        return Transaction::where('airtable_account_id', $airtableId)
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Clear the cache for a budget's accounts
     */
    public function clearAccountCache(Budget $budget): void
    {
        Cache::forget("budget_{$budget->id}_airtable_accounts");
    }

    /**
     * Sync account data from Airtable and update cache
     */
    public function syncAccounts(Budget $budget): array
    {
        $this->clearAccountCache($budget);
        $accounts = $this->getAccountsForBudget($budget);
        
        return [
            'synced_accounts' => $accounts->count(),
            'accounts' => $accounts->toArray()
        ];
    }

    /**
     * Update budget settings in Airtable (if supported)
     * This is a placeholder for future two-way sync capability
     */
    public function updateAccountSetting(string $airtableId, array $settings): bool
    {
        // For now, we only read from Airtable
        // Future enhancement could support updating account settings back to Airtable
        return false;
    }

    /**
     * Get account balance history (placeholder for future enhancement)
     */
    public function getBalanceHistory(string $airtableId, Carbon $startDate, Carbon $endDate): Collection
    {
        // This could be enhanced to track balance changes over time
        // For now, return current balance only
        return collect([]);
    }

    /**
     * Parse account type from Airtable raw data
     */
    protected function parseAccountType(array $fields): string
    {
        $rawData = json_decode($fields['**Raw'] ?? '{}', true);
        return $rawData['type'] ?? 'other';
    }

    /**
     * Parse account subtype from Airtable raw data
     */
    protected function parseAccountSubtype(array $fields): ?string
    {
        $rawData = json_decode($fields['**Raw'] ?? '{}', true);
        return $rawData['subtype'] ?? null;
    }

    /**
     * Parse available balance from Airtable raw data
     */
    protected function parseAvailableBalance(array $fields): ?int
    {
        $rawData = json_decode($fields['**Raw'] ?? '{}', true);
        $availableBalance = $rawData['balances']['available'] ?? null;
        return $availableBalance ? $this->convertToCents($availableBalance) : null;
    }

    /**
     * Determine which group an account belongs to
     */
    protected function getAccountGroup(array $account): string
    {
        $name = strtolower($account['name']);
        $type = $account['type'];
        
        // Credit cards
        if ($type === 'credit' || 
            str_contains($name, 'credit card') || 
            str_contains($name, 'freedom') ||
            str_contains($name, 'home depot')) {
            return 'Credit Cards';
        }
        
        // Loans and mortgages
        if (str_contains($name, 'mortgage') || 
            str_contains($name, 'loan') ||
            str_contains($name, 'heloc')) {
            return 'Loans & Mortgages';
        }
        
        // Banking accounts (checking, savings)
        if ($type === 'depository' || 
            str_contains($name, 'checking') || 
            str_contains($name, 'savings') ||
            str_contains($name, 'performance') ||
            str_contains($name, 'preferred')) {
            return 'Banking & Savings';
        }
        
        // Investment accounts
        if ($type === 'investment' || 
            str_contains($name, 'investment') ||
            str_contains($name, 'brokerage') ||
            str_contains($name, 'retirement') ||
            str_contains($name, 'ira') ||
            str_contains($name, '401k')) {
            return 'Investments';
        }
        
        // Default to "Other"
        return 'Other Accounts';
    }

    /**
     * Get the group type for styling and behavior
     */
    protected function getGroupType(string $groupName): string
    {
        return match($groupName) {
            'Banking & Savings' => 'banking',
            'Credit Cards' => 'credit',
            'Loans & Mortgages' => 'debt',
            'Investments' => 'investment',
            default => 'neutral'
        };
    }

    /**
     * Get icon for account group
     */
    protected function getGroupIcon(string $groupName): string
    {
        return match($groupName) {
            'Banking & Savings' => 'bank',
            'Credit Cards' => 'credit-card',
            'Loans & Mortgages' => 'home',
            'Investments' => 'trending-up',
            default => 'folder'
        };
    }

    /**
     * Get priority order for group display
     */
    protected function getGroupPriority(string $groupName): int
    {
        return match($groupName) {
            'Banking & Savings' => 1,
            'Credit Cards' => 2,
            'Investments' => 3,
            'Loans & Mortgages' => 4,
            'Other Accounts' => 5,
            default => 6
        };
    }

    /**
     * Get user's custom group ordering
     */
    protected function getGroupOrdering(int $userId): array
    {
        return \App\Models\UserPreference::get($userId, 'account_groups_order', []);
    }

    /**
     * Get whether a group is collapsed for a user
     */
    protected function getGroupCollapsedState(int $userId, string $groupName): bool
    {
        $collapsedGroups = \App\Models\UserPreference::get($userId, 'account_groups_collapsed', []);
        return in_array(\Str::slug($groupName), $collapsedGroups);
    }

    /**
     * Apply custom ordering to groups
     */
    protected function applyCustomOrdering(Collection $groups, array $customOrder): Collection
    {
        $orderedGroups = collect();
        
        // First, add groups in custom order
        foreach ($customOrder as $groupId) {
            $group = $groups->first(fn($g) => $g['id'] === $groupId);
            if ($group) {
                $orderedGroups->push($group);
            }
        }
        
        // Then add any remaining groups not in custom order
        $remainingGroups = $groups->reject(fn($g) => in_array($g['id'], $customOrder));
        $orderedGroups = $orderedGroups->concat($remainingGroups);
        
        return $orderedGroups;
    }

    /**
     * Save user's group ordering preference
     */
    public function saveGroupOrdering(int $userId, array $groupOrder): void
    {
        \App\Models\UserPreference::set($userId, 'account_groups_order', $groupOrder);
    }

    /**
     * Toggle group collapsed state
     */
    public function toggleGroupCollapsed(int $userId, string $groupName): bool
    {
        $groupId = \Str::slug($groupName);
        $collapsedGroups = \App\Models\UserPreference::get($userId, 'account_groups_collapsed', []);
        
        if (in_array($groupId, $collapsedGroups)) {
            $collapsedGroups = array_filter($collapsedGroups, fn($id) => $id !== $groupId);
            $isCollapsed = false;
        } else {
            $collapsedGroups[] = $groupId;
            $isCollapsed = true;
        }
        
        \App\Models\UserPreference::set($userId, 'account_groups_collapsed', array_values($collapsedGroups));
        return $isCollapsed;
    }
}
