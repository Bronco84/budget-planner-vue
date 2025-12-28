# Institution URLs Feature

## Overview
This feature adds support for displaying links to third-party bank websites (e.g., chase.com, regions.com) for Plaid-connected accounts. The institution URLs are automatically fetched from the Plaid API during the account linking process and can be backfilled for existing connections.

## Changes Made

### 1. Database Migration
**File:** `database/migrations/2025_12_27_182548_add_institution_url_to_plaid_connections_table.php`

Added a new `institution_url` column to the `plaid_connections` table to store the bank website URL.

```php
$table->string('institution_url')->nullable()->after('institution_logo');
```

### 2. Model Updates
**File:** `app/Models/PlaidConnection.php`

Added `institution_url` to the fillable attributes array to allow mass assignment.

### 3. Service Layer Updates
**File:** `app/Services/PlaidService.php`

Updated the following methods to capture and store institution URLs:
- `getInstitutionDetails()` - Already returns the URL from Plaid API
- `createOrFindConnection()` - Now accepts and stores the institution URL parameter
- `linkMultipleAccounts()` - Passes institution URL when creating connections
- `linkAccount()` - Passes institution URL when creating connections

### 4. Controller Updates
**File:** `app/Http/Controllers/PlaidController.php`

Updated both `import()` and `store()` methods to:
1. Fetch institution details including the URL from Plaid
2. Pass the URL to the PlaidService when linking accounts

### 5. Frontend Updates
**File:** `resources/js/Pages/Budgets/Show.vue`

Added a "Visit Bank Website" link in the account dropdown menu that:
- Only appears for accounts with a Plaid connection that has an institution URL
- Opens the bank website in a new tab
- Uses an external link icon for visual clarity
- Appears at the top of the dropdown menu for easy access

### 6. Backfill Command
**File:** `app/Console/Commands/BackfillInstitutionUrls.php`

Created an artisan command to backfill institution URLs for existing Plaid connections.

**Usage:**
```bash
# Dry run to preview changes
php artisan plaid:backfill-institution-urls --dry-run

# Actually update the database
php artisan plaid:backfill-institution-urls
```

**Features:**
- Processes all connections with an `institution_id` but no `institution_url`
- Fetches institution details from Plaid API
- Shows progress bar and detailed output
- Provides summary statistics
- Supports dry-run mode for testing

## How It Works

### For New Connections
When a user connects a new bank account through Plaid:
1. The Plaid API returns institution metadata including the URL
2. The `PlaidController` extracts the URL from the institution details
3. The `PlaidService` stores the URL in the `plaid_connections` table
4. The URL is immediately available for display in the UI

### For Existing Connections
Run the backfill command to populate URLs for existing connections:
```bash
php artisan plaid:backfill-institution-urls
```

This command:
- Finds all connections without a URL
- Queries the Plaid API for each institution
- Updates the database with the fetched URLs
- Reports success/failure for each connection

## User Experience

When viewing accounts in the budget page:
1. Click the three-dot menu (⋮) next to any Plaid-connected account
2. If the institution URL is available, a "Visit Bank Website" link appears at the top
3. Clicking the link opens the bank's website in a new tab
4. The link uses an external link icon (↗) to indicate it opens in a new window

## Testing

The feature was tested with the following institutions:
- ✅ Regions Bank → https://www.regions.com
- ✅ Capital One → https://www.capitalone.com/
- ✅ Home Depot Credit Card → https://citiretailservices.citibankonline.com/...

## Notes

- Not all institutions in Plaid provide a URL. In these cases, the link simply won't appear.
- The URL is stored at the connection level (not per account) since all accounts from the same institution share the same URL.
- The feature gracefully handles missing URLs - no errors or broken links are shown.
- The backfill command can be run multiple times safely - it only updates connections that need it.

## Future Enhancements

Potential improvements for this feature:
1. Add institution URLs to other views (account edit page, transaction lists, etc.)
2. Display the institution logo alongside the URL link
3. Add a tooltip showing the full URL on hover
4. Track click analytics to see which bank links are most used
5. Add a "quick access" section with frequently visited bank websites

