# Airtable Virtual Accounts Refactor

## Overview

This refactor transforms the budget planner application to use **Airtable as the source of truth for accounts** while **keeping transactions local for forecasting capabilities**. Accounts are now "virtual" - they exist only in Airtable and are fetched dynamically, while transactions remain in the local database with references to Airtable accounts.

## Architecture Changes

### Before: Traditional Account Model
- Local `accounts` table with full account data
- Direct foreign key relationship: `transactions.account_id -> accounts.id`
- Manual account creation and management

### After: Virtual Account Model
- No local account storage (accounts live in Airtable via Fintable)
- Virtual accounts fetched dynamically from Airtable API
- Transactions reference Airtable accounts: `transactions.airtable_account_id`
- Cached virtual account data for performance

## Key Components

### 1. VirtualAccountService (`app/Services/VirtualAccountService.php`)
The central service for managing Airtable-backed accounts:

**Key Methods:**
- `getAccountsForBudget(Budget $budget)` - Fetch all virtual accounts for a budget
- `getAccount(Budget $budget, string $airtableId)` - Get specific account
- `transformAirtableRecord()` - Convert Airtable data to standardized format
- `clearAccountCache()` - Cache management
- `syncAccounts()` - Force refresh from Airtable

**Features:**
- 5-minute caching to reduce API calls
- Standardized account format across legacy and virtual accounts
- Computed fields (transaction counts, status labels)
- Consistent local ID generation from Airtable IDs

### 2. Updated Transaction Model (`app/Models/Transaction.php`)
Enhanced to work with virtual accounts:

**New Fields:**
- `airtable_account_id` - References Airtable account record ID
- `computed_account_name` - Cached account name for performance
- `airtable_metadata` - Full Airtable record data
- `is_airtable_imported` - Import tracking flag
- `account_id` - Now nullable (legacy compatibility)

**New Methods:**
- `getVirtualAccountAttribute()` - Access virtual account data
- `getAccountNameAttribute()` - Smart account name resolution
- `scopeForAirtableAccount()` - Query by Airtable account
- `scopeForecast()` / `scopeActual()` - Forecast vs actual transactions

### 3. Updated Budget Model (`app/Models/Budget.php`)
Enhanced with virtual account support:

**New Methods:**
- `getVirtualAccountsAttribute()` - Fetch virtual accounts for this budget
- `getAllAccounts()` - Combined virtual + legacy accounts
- `getActiveAccountsAttribute()` - Active virtual accounts only

### 4. Enhanced AirtableSyncService (`app/Services/AirtableSyncService.php`)
Updated to work with virtual accounts:

**New Methods:**
- `syncTransactionsForVirtualAccount()` - Sync transactions for a virtual account
- `processVirtualTransaction()` - Process individual Airtable transactions
- `getAccountNameFromAirtable()` - Cache account names

### 5. Updated Controllers
**BudgetController:**
- Uses VirtualAccountService for account data
- Passes virtual accounts to frontend
- Updated transaction queries to work with virtual accounts

**AirtableController:**
- Enhanced summary view with virtual account data
- Integration with VirtualAccountService

## Database Changes

### Transaction Table Updates
```sql
-- New columns added via migration
ALTER TABLE transactions ADD airtable_account_id VARCHAR(255) NULL;
ALTER TABLE transactions ADD airtable_metadata JSON NULL;
ALTER TABLE transactions ADD computed_account_name VARCHAR(255) NULL;
ALTER TABLE transactions MODIFY account_id INT NULL; -- Made nullable

-- Indexes for performance
CREATE INDEX idx_transactions_airtable_account_id ON transactions(airtable_account_id);
```

### Budget Table Updates
```sql
-- Optional tracking fields
ALTER TABLE budgets ADD airtable_base_id VARCHAR(255) NULL;
ALTER TABLE budgets ADD last_airtable_sync TIMESTAMP NULL;
ALTER TABLE budgets ADD airtable_sync_summary JSON NULL;
```

## Data Flow

### Account Data Flow
1. **Fintable** → **Airtable** (automatic financial data sync)
2. **Airtable** → **VirtualAccountService** (on-demand fetch with caching)
3. **VirtualAccountService** → **Controllers/Views** (standardized account format)

### Transaction Data Flow
1. **Fintable** → **Airtable** (transaction data)
2. **AirtableSyncService** → **Local Database** (import transactions with virtual account references)
3. **Local Database** → **Forecasting/Budget Analysis** (projections, categorization, etc.)

## Migration Strategy

### Migration Command: `php artisan accounts:migrate-to-virtual`

**Features:**
- Automatic matching of legacy accounts to virtual accounts
- Multiple matching strategies (name, partial name, type)
- Interactive selection for ambiguous matches
- Dry-run mode for safe testing
- Per-budget or all-budget migration

**Matching Strategies:**
1. Exact name match
2. Partial name match (case insensitive)
3. Account type match (if unique)
4. Interactive user selection

### Usage Examples:
```bash
# Dry run to see what would be migrated
php artisan accounts:migrate-to-virtual --dry-run

# Migrate specific budget
php artisan accounts:migrate-to-virtual --budget=1

# Migrate all budgets
php artisan accounts:migrate-to-virtual
```

## Benefits

### ✅ **Real-time Account Data**
- Always up-to-date balances from Fintable
- No manual account management needed
- Automatic new account discovery

### ✅ **Simplified Data Management**
- Single source of truth for account data (Airtable)
- No data duplication or sync conflicts
- Reduced storage requirements

### ✅ **Preserved Forecasting**
- Transactions remain local for complex forecasting logic
- Historical transaction analysis preserved
- Budget projections and patterns maintained

### ✅ **Backward Compatibility**
- Legacy accounts still supported during transition
- Gradual migration possible
- No data loss during refactor

### ✅ **Performance Optimized**
- Intelligent caching reduces API calls
- Computed fields for frequently accessed data
- Efficient database queries

## Environment Configuration

No changes needed - uses existing Airtable configuration:

```env
AIRTABLE_API_KEY=your_api_key
AIRTABLE_BASE_ID=your_base_id
AIRTABLE_ACCOUNTS_TABLE=accounts
AIRTABLE_TRANSACTIONS_TABLE=transactions
```

## API Integration

### Airtable API Usage
- **GET** `/accounts` - Fetch account data with caching
- **GET** `/transactions` - Sync transaction data
- Rate limiting and error handling built-in
- Automatic retry logic for failed requests

### Frontend Changes Required
- Update account selection components to use `airtable_id` instead of `id`
- Handle virtual account format in account listings
- Support both legacy and virtual accounts during transition

## Testing

The refactor maintains full test compatibility:
- Existing transaction tests continue to work
- New virtual account tests added
- Migration testing with dry-run mode
- Integration tests for Airtable service

## Future Enhancements

### Potential Improvements:
1. **Two-way Sync**: Update Airtable account settings from app
2. **Real-time Updates**: WebSocket integration for live account updates
3. **Advanced Caching**: Redis-based caching for multi-user scenarios
4. **Account Categories**: Sync account categories/tags from Airtable
5. **Balance History**: Track balance changes over time

## Rollback Strategy

If needed, the refactor can be rolled back:

1. **Restore Legacy Account References**:
   ```sql
   UPDATE transactions 
   SET account_id = (SELECT id FROM accounts WHERE name = computed_account_name)
   WHERE account_id IS NULL AND computed_account_name IS NOT NULL;
   ```

2. **Remove Virtual Account Fields**:
   ```bash
   php artisan migrate:rollback --step=2
   ```

3. **Restore Controller Logic**:
   - Revert controllers to use `$budget->accounts` relationship
   - Remove VirtualAccountService dependencies

## Summary

This refactor successfully transforms the application to use Airtable as the authoritative source for account data while preserving all local transaction functionality needed for budgeting and forecasting. The virtual account approach provides real-time financial data while maintaining the flexibility and performance of local transaction storage.

The implementation is production-ready with proper error handling, caching, migration tools, and backward compatibility during the transition period.
