# Hybrid Account Architecture - Final Solution

## Overview

After encountering the limitation with pure virtual accounts (recurring transactions, forecasting, and complex queries requiring local Account models), we've implemented a **hybrid approach** that provides the best of both worlds:

- ✅ **Real-time data** from Airtable/Fintable  
- ✅ **Local Account models** for complex operations
- ✅ **Automatic synchronization** between Airtable and local database
- ✅ **Backward compatibility** with existing features

## Problem Statement

### Issues with Pure Virtual Accounts:
1. **RecurringTransactionTemplates** depend on `account_id` foreign keys
2. **Forecasting logic** requires Account model relationships
3. **Complex queries** break without local Account records
4. **Performance issues** from constant API calls
5. **Transaction projections** need stable account references

### The Solution: Hybrid Account Sync

Instead of eliminating local accounts, we **synchronize them** with Airtable as the authoritative source.

## Architecture Components

### 1. HybridAccountService (`app/Services/HybridAccountService.php`)

The central service that manages synchronization between Airtable and local accounts.

**Key Methods:**
- `syncAccountsForBudget(Budget $budget)` - Sync all Airtable accounts to local records
- `getOrCreateLocalAccount()` - Get/create local account for Airtable account
- `getAccountsForBudget()` - Get synchronized accounts (auto-syncs first)
- `refreshAccountBalance()` - Update single account from Airtable
- `markMissingAccountsInactive()` - Handle deleted Airtable accounts

**Sync Strategy:**
```php
// Find existing by airtable_account_id
$existingAccount = Account::where('airtable_account_id', $virtualAccount['airtable_id'])->first();

if ($existingAccount) {
    $existingAccount->update($syncData); // Update from Airtable
} else {
    Account::create($syncData); // Create new local account
}
```

### 2. Enhanced Account Model (`app/Models/Account.php`)

**New Fields:**
- `airtable_account_id` - Links to Airtable record ID
- `airtable_metadata` - Full Airtable record data  
- `last_airtable_sync` - Timestamp of last sync

**New Methods:**
- `isAirtableSynced()` - Check if account is linked to Airtable
- `needsSync()` - Check if sync is stale (>5 minutes)
- `scopeAirtableSynced()` - Query Airtable-linked accounts
- `scopeLegacy()` - Query manually created accounts

### 3. Updated BudgetController

**Before (Broken):**
```php
$virtualAccounts = $virtualAccountService->getAccountsForBudget($budget);
// $account undefined - breaks recurring transactions
```

**After (Working):**
```php
$accounts = $hybridAccountService->getAccountsForBudget($budget);
$account = $hybridAccountService->getAccount($budget, $accountId);
// $account is real Account model - works with all existing logic
```

### 4. Smart Projections

Enhanced `RecurringTransactionService` with pattern-based projections:

**Traditional Method:** Requires RecurringTransactionTemplates
**New Method:** Analyzes transaction patterns automatically

```php
// Detect monthly patterns from historical data
$monthlyPatterns = $this->findMonthlyPatterns($existingTransactions);

// Project future transactions based on patterns
foreach ($monthlyPatterns as $pattern) {
    $projected = $this->projectFromPattern($pattern, $virtualAccount, $startDate, $endDate);
}
```

## Data Flow

### 1. **Account Synchronization**
```
Fintable → Airtable → HybridAccountService → Local Account Records
```

### 2. **Transaction Management**
```
Airtable Transactions → Local Transactions (with account_id references)
```

### 3. **Forecasting/Budgeting**
```
Local Account Models → RecurringTransactionTemplates → Projections
Local Transactions → Pattern Detection → Smart Projections
```

## Database Schema

### Accounts Table Updates
```sql
-- New columns for Airtable synchronization
ALTER TABLE accounts ADD airtable_account_id VARCHAR(255) NULL;
ALTER TABLE accounts ADD airtable_metadata JSON NULL;  
ALTER TABLE accounts ADD last_airtable_sync TIMESTAMP NULL;

-- Indexes for performance
CREATE INDEX idx_accounts_airtable_id ON accounts(airtable_account_id);
CREATE INDEX idx_accounts_budget_airtable ON accounts(budget_id, airtable_account_id);
```

### Transactions Table (Already Updated)
```sql
-- Support for both local and virtual account references
account_id INT NULL,                    -- Local account (for recurring transactions)
airtable_account_id VARCHAR(255) NULL,  -- Airtable account reference
computed_account_name VARCHAR(255) NULL, -- Cached account name
airtable_metadata JSON NULL             -- Full Airtable data
```

## Sync Management

### Automatic Sync Command
```bash
# Sync all budgets
php artisan accounts:sync-airtable --all

# Sync specific budget  
php artisan accounts:sync-airtable --budget=1

# Force sync (ignore cache)
php artisan accounts:sync-airtable --all --force
```

### Sync Triggers
1. **On Budget View**: Auto-sync when displaying budget
2. **Scheduled Task**: Daily sync via cron
3. **Manual Command**: On-demand sync
4. **API Webhook**: Real-time sync when Airtable changes (future)

## Benefits of Hybrid Approach

### ✅ **Maintains All Existing Features**
- RecurringTransactionTemplates work unchanged
- Forecasting and projections continue to function
- Complex queries and relationships preserved
- Account filtering and grouping still works

### ✅ **Adds Real-time Data**
- Account balances always current from Fintable
- New accounts automatically discovered
- Account metadata synced from Airtable
- Removes manual account management burden

### ✅ **Performance Optimized**
- Local queries for complex operations
- Cached account data reduces API calls
- Smart sync only when needed
- Background sync for real-time updates

### ✅ **Handles Edge Cases**
- Missing Airtable accounts marked inactive
- Legacy accounts supported during transition
- Graceful handling of sync failures
- Account matching by multiple strategies

## Migration Strategy

### For Existing Accounts:
1. **Keep existing Account records** during transition
2. **Link to Airtable** using migration command:
   ```bash
   php artisan accounts:migrate-to-virtual --budget=1
   ```
3. **Gradual transition** - old accounts become Airtable-synced

### For New Accounts:
1. **Automatic creation** from Airtable data
2. **No manual account setup** required
3. **Immediate integration** with all existing features

## Implementation Status

### ✅ **Completed:**
- HybridAccountService implementation
- Account model enhancements  
- Database migrations
- BudgetController updates
- Sync command creation
- Pattern-based projections

### 🔄 **Next Steps:**
- Test sync functionality with real Airtable data
- Update frontend to handle sync status
- Add scheduled sync task
- Implement webhook for real-time updates

## Error Handling

The hybrid approach includes comprehensive error handling:

### Sync Errors:
- Individual account sync failures logged
- Partial sync success reported
- Retry logic for transient failures
- Graceful degradation when Airtable unavailable

### Missing Data:
- Local accounts preserved if Airtable sync fails
- Default values for missing Airtable fields
- Clear error messages for configuration issues

## Summary

The hybrid architecture solves the fundamental tension between:
- **Real-time financial data** (Airtable/Fintable)
- **Complex application features** (local Account models)

By maintaining synchronized local Account records, we get:
- ✅ All the benefits of virtual accounts (real-time data)
- ✅ All the benefits of local accounts (complex queries, relationships)
- ✅ Seamless migration path from existing implementation
- ✅ Future flexibility for advanced features

This approach provides the **best user experience** with **minimal technical debt** and **maximum feature compatibility**.
